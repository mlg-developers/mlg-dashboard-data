<?php

namespace Tests\Unit;

use App\Services\TvDashboardService;
use Tests\TestCase;

/**
 * Waiting-patient rules for the TV dashboards. All rows are synthetic (no real patient data).
 */
class TvDashboardServiceTest extends TestCase
{
    private function visit(array $overrides = []): array
    {
        static $n = 0;
        $n++;

        return array_merge([
            'mrNumber' => "MR{$n}", 'visitNum' => '1', 'visitDate' => '20260101',
            'clinicCode' => '204', 'clinicName' => 'MLG GENERAL OPD',
            'doctCode' => 'D1   ', 'billDoctName' => 'BILLING  DOCTOR',
            'consDoctor' => ' ', 'consDoctName' => '',
            'consTime' => '08:00:00', 'doctConsTm' => ' ', 'visitStatus' => ' ',
        ], $overrides);
    }

    public function test_blank_and_other_non_completed_statuses_are_waiting_completed_is_seen(): void
    {
        $stats = (new TvDashboardService())->aggregate([
            $this->visit(['visitStatus' => ' ']),
            $this->visit(['visitStatus' => 'V']),
            $this->visit(['visitStatus' => 'C', 'consDoctor' => 'D2', 'consDoctName' => 'Consulting Doc']),
        ]);

        $this->assertSame(2, $stats['204']['waiting']);
        $this->assertSame(1, $stats['204']['seen']);
    }

    public function test_waiting_goes_to_billing_doctor_and_seen_to_consulting_doctor(): void
    {
        $stats = (new TvDashboardService())->aggregate([
            $this->visit(['doctCode' => 'D1', 'visitStatus' => ' ']),
            // billed by D1, but D2 saw the patient -> counts for D2, not for D1
            $this->visit(['doctCode' => 'D1', 'visitStatus' => 'C', 'consDoctor' => 'D2 ', 'consDoctName' => 'Doc  Two']),
        ]);

        $doctors = $stats['204']['doctors'];
        $this->assertSame(['name' => 'BILLING DOCTOR', 'waiting' => 1, 'seen' => 0], $doctors['D1']);
        $this->assertSame(['name' => 'Doc Two', 'waiting' => 0, 'seen' => 1], $doctors['D2']);
    }

    public function test_duplicate_visit_is_counted_once_and_completed_copy_wins(): void
    {
        $base = $this->visit(['mrNumber' => 'X1', 'visitNum' => '5']);
        $stats = (new TvDashboardService())->aggregate([
            $base,
            array_merge($base, ['visitStatus' => 'C', 'consDoctor' => 'D2', 'consDoctName' => 'Doc Two']),
        ]);

        $this->assertSame(0, $stats['204']['waiting']);
        $this->assertSame(1, $stats['204']['seen']);
    }

    public function test_same_patient_with_different_visit_numbers_are_separate_visits(): void
    {
        $stats = (new TvDashboardService())->aggregate([
            $this->visit(['mrNumber' => 'X1', 'visitNum' => '5']),
            $this->visit(['mrNumber' => 'X1', 'visitNum' => '6']),
        ]);

        $this->assertSame(2, $stats['204']['waiting']);
    }

    public function test_emergency_visit_with_recorded_cons_time_counts_as_consulted(): void
    {
        $stats = (new TvDashboardService())->aggregate([
            $this->visit(['clinicCode' => '900', 'clinicName' => 'MLG EMERGENCY MEDICINE', 'visitStatus' => ' ', 'consTime' => '10:00:00']),
            $this->visit(['clinicCode' => '900', 'clinicName' => 'MLG EMERGENCY MEDICINE', 'visitStatus' => ' ', 'consTime' => '']),
        ]);

        $this->assertSame(1, $stats['900']['waiting']);
        $this->assertSame(1, $stats['900']['seen']);
    }

    public function test_output_contains_no_patient_fields(): void
    {
        $json = json_encode((new TvDashboardService())->aggregate([$this->visit(['mrNumber' => 'SECRET-MR'])]));

        $this->assertStringNotContainsString('SECRET-MR', $json);
        $this->assertStringNotContainsString('mrNumber', $json);
    }

    private function clinicWith(array $doctors): array
    {
        return [[
            'code' => '204', 'waiting' => array_sum(array_column($doctors, 'waiting')),
            'doctors' => array_map(fn ($d) => $d + ['seen' => 0, 'booked' => $d['waiting'], 'name' => $d['code']], $doctors),
        ]];
    }

    public function test_move_shifts_waiting_patients_between_doctors_and_keeps_clinic_total(): void
    {
        $clinics = $this->clinicWith([['code' => 'A', 'waiting' => 5], ['code' => 'B', 'waiting' => 1]]);
        $out = (new TvDashboardService())->applyOverrides($clinics, [], [
            ['clinic_code' => '204', 'from_doctor_code' => 'A', 'to_doctor_code' => 'B', 'count' => 2],
        ]);

        $docs = array_column($out[0]['doctors'], null, 'code');
        $this->assertSame(3, $docs['A']['waiting']);
        $this->assertSame(3, $docs['B']['waiting']);
        $this->assertSame(2, $docs['A']['moved_out']);
        $this->assertSame(2, $docs['B']['moved_in']);
        $this->assertSame(3, $docs['A']['booked']);
        $this->assertSame(3, $docs['B']['booked']);
        $this->assertSame(6, $out[0]['waiting']);
    }

    public function test_move_is_capped_by_patients_still_waiting_for_the_first_doctor(): void
    {
        $clinics = $this->clinicWith([['code' => 'A', 'waiting' => 1], ['code' => 'B', 'waiting' => 0]]);
        $out = (new TvDashboardService())->applyOverrides($clinics, [], [
            ['clinic_code' => '204', 'from_doctor_code' => 'A', 'to_doctor_code' => 'B', 'count' => 4],
        ]);

        $docs = array_column($out[0]['doctors'], null, 'code');
        $this->assertSame(0, $docs['A']['waiting']);
        $this->assertSame(1, $docs['B']['waiting']);
    }

    public function test_switched_off_doctor_is_flagged_and_listed_last(): void
    {
        $clinics = $this->clinicWith([['code' => 'A', 'waiting' => 9], ['code' => 'B', 'waiting' => 1]]);
        $out = (new TvDashboardService())->applyOverrides($clinics, ['204|A' => 'off'], []);

        $this->assertSame(['B', 'A'], array_column($out[0]['doctors'], 'code'));
        $this->assertTrue($out[0]['doctors'][1]['off']);
        $this->assertFalse($out[0]['doctors'][0]['off']);
    }

    public function test_moves_to_unknown_doctors_or_with_unavailable_data_are_ignored(): void
    {
        $svc = new TvDashboardService();
        $known = $this->clinicWith([['code' => 'A', 'waiting' => 3]]);
        $out = $svc->applyOverrides($known, [], [['clinic_code' => '204', 'from_doctor_code' => 'A', 'to_doctor_code' => 'ZZ', 'count' => 1]]);
        $this->assertSame(3, $out[0]['doctors'][0]['waiting']);

        $unknownData = [[
            'code' => '204', 'waiting' => null,
            'doctors' => [
                ['code' => 'A', 'name' => 'A', 'waiting' => null, 'seen' => null, 'booked' => null],
                ['code' => 'B', 'name' => 'B', 'waiting' => null, 'seen' => null, 'booked' => null],
            ],
        ]];
        $out = $svc->applyOverrides($unknownData, [], [['clinic_code' => '204', 'from_doctor_code' => 'A', 'to_doctor_code' => 'B', 'count' => 1]]);
        $this->assertNull($out[0]['doctors'][0]['waiting']);
        $this->assertNull($out[0]['doctors'][1]['waiting']);
    }

    public function test_floor_filter_keeps_only_that_floor_and_recomputes_totals(): void
    {
        $payload = [
            'clinics' => [
                ['code' => '1', 'floor_code' => '001', 'waiting' => 4, 'seen' => 1, 'doctors' => []],
                ['code' => '2', 'floor_code' => '002', 'waiting' => 9, 'seen' => 2, 'doctors' => []],
            ],
            'floors' => [['code' => '001', 'name' => null], ['code' => '002', 'name' => null]],
            'totals' => [],
        ];
        $out = (new TvDashboardService())->forFloor($payload, '002');

        $this->assertSame(['2'], array_column($out['clinics'], 'code'));
        $this->assertSame(9, $out['totals']['waiting']);
        $this->assertSame('002', $out['floor_filter']);
        $this->assertCount(2, $out['floors']); // the full list stays so the TV can be re-registered
        $this->assertSame($payload, (new TvDashboardService())->forFloor($payload, ''));
    }

    public function test_in_active_mode_an_assigned_doctor_without_patients_is_hidden_unless_switched_on(): void
    {
        config(['tv.doctor_visibility' => 'active']);
        $svc = new TvDashboardService();
        $clinics = $this->clinicWith([['code' => 'A', 'waiting' => 2], ['code' => 'IDLE', 'waiting' => 0], ['code' => 'FORCED', 'waiting' => 0]]);
        $out = $svc->applyOverrides($clinics, ['204|FORCED' => 'on'], []);
        $docs = array_column($out[0]['doctors'], null, 'code');

        $this->assertTrue($docs['A']['visible']);
        $this->assertFalse($docs['IDLE']['visible']);
        $this->assertTrue($docs['FORCED']['visible']);

        $tv = $svc->forTv(['clinics' => $out]);
        $this->assertSame(['A', 'FORCED'], array_column($tv['clinics'][0]['doctors'], 'code'));
    }

    public function test_off_on_a_doctor_without_patients_does_not_make_him_visible_in_active_mode(): void
    {
        config(['tv.doctor_visibility' => 'active']);
        $out = (new TvDashboardService())->applyOverrides($this->clinicWith([['code' => 'IDLE', 'waiting' => 0]]), ['204|IDLE' => 'off'], []);

        $this->assertFalse($out[0]['doctors'][0]['visible']);
        $this->assertTrue($out[0]['doctors'][0]['off']);
    }

    public function test_doctor_who_received_moved_patients_is_visible(): void
    {
        $clinics = $this->clinicWith([['code' => 'A', 'waiting' => 3], ['code' => 'B', 'waiting' => 0]]);
        $out = (new TvDashboardService())->applyOverrides($clinics, [], [
            ['clinic_code' => '204', 'from_doctor_code' => 'A', 'to_doctor_code' => 'B', 'count' => 1],
        ]);

        $this->assertTrue(array_column($out[0]['doctors'], null, 'code')['B']['visible']);
    }

    public function test_by_default_every_assigned_doctor_is_shown_until_switched_off(): void
    {
        $svc = new TvDashboardService();
        $clinics = $this->clinicWith([['code' => 'A', 'waiting' => 2], ['code' => 'IDLE', 'waiting' => 0], ['code' => 'GONE', 'waiting' => 0]]);
        $out = $svc->applyOverrides($clinics, ['204|GONE' => 'off'], []);
        $docs = array_column($out[0]['doctors'], null, 'code');

        $this->assertTrue($docs['IDLE']['visible']);
        $this->assertTrue($docs['IDLE']['default_visible']);
        $this->assertTrue($docs['GONE']['visible']);
        $this->assertTrue($docs['GONE']['off']); // still listed, as "absent"
        $this->assertSame(['A', 'IDLE', 'GONE'], array_column($out[0]['doctors'], 'code')); // absent last
    }

    public function test_v_is_consulted_when_any_consultation_field_has_data_otherwise_pending_for_the_billing_doctor(): void
    {
        $stats = (new TvDashboardService())->aggregate([
            // everything filled: seen by the consulting doctor D2
            $this->visit(['doctCode' => 'D1', 'visitStatus' => 'V', 'consDoctor' => 'D2 ', 'consDoctName' => 'Doc  Two', 'doctConsDt' => '20261124', 'doctConsTm' => '10:35:34']),
            // only the consultation time has data (consDoctor blank): consulted, it stays with the billing doctor D1
            $this->visit(['doctCode' => 'D1', 'visitStatus' => 'V', 'consDoctor' => '      ', 'consDoctName' => '', 'doctConsDt' => '20261124', 'doctConsTm' => '10:35:34']),
            // only the consultation date
            $this->visit(['doctCode' => 'D1', 'visitStatus' => 'V', 'doctConsDt' => '20261124']),
            // only the consulting doctor
            $this->visit(['doctCode' => 'D1', 'visitStatus' => 'V', 'consDoctor' => 'D2', 'consDoctName' => 'Doc Two']),
            // all four empty: vitals done, still waiting for the billing doctor D1
            $this->visit(['doctCode' => 'D1', 'visitStatus' => 'V', 'consDoctor' => '      ', 'consDoctName' => '', 'doctConsDt' => ' ', 'doctConsTm' => ' ']),
        ]);

        $this->assertSame(1, $stats['204']['waiting']);
        $this->assertSame(4, $stats['204']['seen']);
        $this->assertSame(['name' => 'BILLING DOCTOR', 'waiting' => 1, 'seen' => 2], $stats['204']['doctors']['D1']);
        $this->assertSame(['name' => 'Doc Two', 'waiting' => 0, 'seen' => 2], $stats['204']['doctors']['D2']);
    }

    public function test_other_statuses_are_not_affected_by_the_v_rule(): void
    {
        $stats = (new TvDashboardService())->aggregate([
            $this->visit(['visitStatus' => ' ', 'consDoctor' => 'D2', 'doctConsDt' => '20261124', 'doctConsTm' => '10:35:34']), // blank stays pending
            $this->visit(['visitStatus' => 'C']),                                                                              // C is consulted
        ]);

        $this->assertSame(1, $stats['204']['waiting']);
        $this->assertSame(1, $stats['204']['seen']);
    }
}
