<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Data for the public TV dashboards (CLINICS TODAY and DOCTORS & PATIENTS).
 *
 * Combines three Jeeva (HIS) APIs:
 *   API 2 clinic_weekdays  - which clinics run today, their department and floor
 *   API 1 clinic_doctors   - the doctors assigned to each clinic
 *   API 3 dashboard        - today's visits, used ONLY to count waiting / seen patients
 *
 * PRIVACY: patient records stay inside this class. They are aggregated into counters in memory
 * and dropped; nothing patient-level is returned, cached or logged.
 */
class TvDashboardService
{
    /**
     * Full response for a date (default: today in the hospital's timezone).
     */
    public function build(?string $date = null): array
    {
        $tz = config('tv.timezone');
        $day = $date ? Carbon::createFromFormat('Y-m-d', $date, $tz)->startOfDay() : Carbon::now($tz)->startOfDay();
        $ymd = $day->format('Ymd');
        $ttl = $day->isToday() ? config('tv.live_cache_seconds') : 600;

        // One caller rebuilds, everybody else waits for (and reuses) the result.
        $cacheKey = "tv:payload:{$ymd}";
        if (($cached = Cache::get($cacheKey)) !== null) {
            return $cached;
        }

        $lock = Cache::lock("tv:build:{$ymd}", 60);
        try {
            $lock->block(15);
        } catch (\Throwable) {
            // could not get the lock in time: fall through and build anyway
        }

        try {
            if (($cached = Cache::get($cacheKey)) !== null) {
                return $cached;
            }
            $payload = $this->compose($day);
            // Do not cache an all-failed result for long: retry soon.
            $ok = $payload['sources']['schedule'] !== 'unavailable';
            Cache::put($cacheKey, $payload, $ok ? $ttl : 10);

            return $payload;
        } finally {
            optional($lock)->release();
        }
    }

    private function compose(Carbon $day): array
    {
        [$schedule, $scheduleState] = $this->fetchSchedule($day->dayOfWeekIso);
        [$patients, $patientsState, $patientsAt] = $this->fetchPatientStats($day->format('Ymd'));

        $clinics = [];
        $doctorsState = 'ok';

        if ($schedule !== null) {
            $codes = array_column($schedule, 'clinicCode');
            [$doctorLists, $failedDoctorFetches] = $this->fetchDoctors($codes);
            if ($failedDoctorFetches > 0) {
                $doctorsState = $failedDoctorFetches === count($codes) ? 'unavailable' : 'partial';
            }

            foreach ($schedule as $row) {
                $clinics[] = $this->buildClinic($row, $doctorLists[$row['clinicCode']] ?? null, $patients[$row['clinicCode']] ?? null, $patientsState);
            }
        }

        // accountant controls: permanent freeze / migration rules, doctors switched off for the day and
        // waiting patients moved between doctors
        [$rules, $rulesState] = $this->loadRules();
        $clinics = $this->applyRules($clinics, $rules);
        [$off, $moves, $overridesState] = $this->loadOverrides($day->format('Y-m-d'));
        $clinics = $this->applyOverrides($clinics, $off, $moves);

        usort($clinics, function ($a, $b) {
            // unknown (null) counts sink to the bottom; otherwise busiest first
            return [$b['waiting'] ?? -1, $a['name']] <=> [$a['waiting'] ?? -1, $b['name']];
        });

        return [
            'status' => $schedule === null ? 'unavailable' : 'success',
            'date' => $day->format('Y-m-d'),
            'weekday' => $day->dayOfWeekIso,
            'generated_at' => Carbon::now(config('tv.timezone'))->toIso8601String(),
            'patients_as_of' => $patientsAt ? Carbon::createFromTimestamp($patientsAt, config('tv.timezone'))->toIso8601String() : null,
            'sources' => [
                'schedule' => $scheduleState,
                'doctors' => $schedule === null ? 'unavailable' : $doctorsState,
                'patients' => $patientsState,
                'overrides' => $overridesState,
                'rules' => $rulesState,
            ],
            'floors' => $this->floorList($clinics),
            'floor_filter' => null,
            'totals' => $this->totals($clinics),
            'clinics' => $clinics,
        ];
    }

    /** "SPECIALIST", "Super Specialist", "SUPERSPECIALIST", "Medical Specialist"... (spacing/case/typos of the word). */
    private function isSpecialist(?string $qualification): bool
    {
        $q = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $qualification));

        return $q !== '' && (str_contains($q, 'specialist') || str_contains($q, 'superspecialis'));
    }

    /** Clinics (by name) that keep every doctor, whatever the qualification: physiotherapy. */
    private function isQualificationExempt(string $clinicName): bool
    {
        foreach (config('tv.all_qualification_clinics', ['PHYSIOTHERAPY']) as $needle) {
            if ($needle !== '' && stripos($clinicName, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Every floor that has a clinic today, for the "which floor is this TV on" choice. */
    private function floorList(array $clinics): array
    {
        $floors = [];
        foreach ($clinics as $c) {
            if ($c['floor_code'] !== null) {
                $floors[$c['floor_code']] = ['code' => $c['floor_code'], 'name' => $c['floor_name']];
            }
        }
        // when floors are named in TV_FLOOR_NAMES, those are the only floors a TV can be registered to
        $named = array_keys((array) config('tv.floor_names', []));
        if ($named !== []) {
            $floors = array_intersect_key($floors, array_flip($named));
        }
        ksort($floors);

        return array_values($floors);
    }

    /**
     * Keeps only the clinics of one floor (a TV registered to that floor), with totals recomputed so
     * nothing from other floors leaks into its numbers. `floors` stays complete for the settings list.
     */
    public function forFloor(array $payload, ?string $floor): array
    {
        $floor = $floor === null ? '' : trim($floor);
        if ($floor === '') {
            return $payload;
        }
        $payload['clinics'] = array_values(array_filter($payload['clinics'], fn ($c) => $c['floor_code'] === $floor));
        $payload['totals'] = $this->totals($payload['clinics']);
        $payload['floor_filter'] = $floor;

        return $payload;
    }

    private function buildClinic(array $row, ?array $assigned, ?array $stats, string $patientsState): array
    {
        $floorCode = trim((string) ($row['floorCode'] ?? ''));
        $known = $patientsState !== 'unavailable'; // zero is only a real zero when the data was available

        // Only specialists and super specialists are shown (API 1 "doctQuali"), except in physiotherapy.
        // A doctor's qualification comes from this clinic's API 1 list, so a doctor that has visits today
        // but is listed there as e.g. "MD" is left out too. Doctors API 1 does not list are kept (unknown).
        $specialistOnly = ! $this->isQualificationExempt((string) ($row['clinicName'] ?? ''));
        $notSpecialist = [];
        if ($specialistOnly) {
            foreach ($assigned ?? [] as $d) {
                if (! $this->isSpecialist($d['qualification'] ?? null)) {
                    $notSpecialist[$d['code']] = true;
                }
            }
            $assigned = array_values(array_filter($assigned ?? [], fn ($d) => ! isset($notSpecialist[$d['code']])));
            if (is_array($stats) && isset($stats['doctors'])) {
                $stats['doctors'] = array_diff_key($stats['doctors'], $notSpecialist);
            }
        }

        // doctors keyed by code: assigned ones first (API 1), then anyone who actually has visits today
        $doctors = [];
        foreach ($assigned ?? [] as $d) {
            $doctors[$d['code']] = $d + ['booked' => $known ? 0 : null, 'waiting' => $known ? 0 : null, 'seen' => $known ? 0 : null, 'assigned' => true];
        }
        foreach (($stats['doctors'] ?? []) as $code => $s) {
            $doctors[$code] ??= [
                'code' => $code,
                'name' => $s['name'],
                'qualification' => null,
                'assigned' => false,
            ];
            $doctors[$code]['waiting'] = $s['waiting'];
            $doctors[$code]['seen'] = $s['seen'];
            $doctors[$code]['booked'] = $s['waiting'] + $s['seen'];
        }

        $list = array_values($doctors);
        usort($list, fn ($a, $b) => [$b['waiting'] ?? -1, $b['seen'] ?? -1, $a['name']] <=> [$a['waiting'] ?? -1, $a['seen'] ?? -1, $b['name']]);

        $waiting = $known ? (int) ($stats['waiting'] ?? 0) : null;
        $seen = $known ? (int) ($stats['seen'] ?? 0) : null;

        return [
            'code' => $row['clinicCode'],
            'name' => $this->clean($row['clinicName'] ?? ''),
            'department' => $this->clean($row['deptName'] ?? ''),
            'department_code' => trim((string) ($row['deptCode'] ?? '')) ?: null,
            'floor_code' => $floorCode !== '' ? $floorCode : null,
            'floor_name' => config('tv.floor_names')[$floorCode] ?? null,
            'waiting' => $waiting,
            'seen' => $seen,
            'booked' => $known ? $waiting + $seen : null,
            'doctors' => $list,
        ];
    }

    private function totals(array $clinics): array
    {
        $known = array_filter($clinics, fn ($c) => $c['waiting'] !== null);
        $withPatients = 0;
        foreach ($clinics as $c) {
            foreach ($c['doctors'] as $d) {
                if (($d['booked'] ?? 0) > 0 && empty($d['off'])) {
                    $withPatients++;
                }
            }
        }

        return [
            'clinics' => count($clinics),
            'doctors_with_patients' => $withPatients,
            'waiting' => $known ? array_sum(array_column($known, 'waiting')) : null,
            'seen' => $known ? array_sum(array_column($known, 'seen')) : null,
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Accountant controls (database). The public TV keeps working when they cannot be read.
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array{0: array<string, string>, 1: array<int, array>, 2: string} [doctor switches "clinic|doctor" => on|off, moves, state]
     */
    private function loadOverrides(string $date): array
    {
        try {
            $off = [];
            foreach (DB::table('tv_doctor_overrides')->where('date', $date)->get(['clinic_code', 'doctor_code', 'state']) as $row) {
                $off["{$row->clinic_code}|{$row->doctor_code}"] = $row->state; // 'on' | 'off'
            }
            $moves = DB::table('tv_waiting_moves')->where('date', $date)->where('count', '>', 0)
                ->orderBy('id')->get(['clinic_code', 'from_doctor_code', 'to_doctor_code', 'count'])
                ->map(fn ($m) => (array) $m)->all();

            return [$off, $moves, 'ok'];
        } catch (\Throwable $e) {
            Log::warning('[TvDashboard] accountant controls unavailable: '.$e->getMessage());

            return [[], [], 'unavailable'];
        }
    }

    /**
     * Applies the accountant's switches and moves to the computed clinics. Pure, so it can be tested.
     *
     *  - which doctors are shown depends on tv.doctor_visibility: every assigned doctor ('assigned', the
     *    default) or only those with patients in this clinic today (billed, seen or moved in) plus the ones
     *    the accountant switched 'on' ('active'). Note that Jeeva lists the same doctor under dozens of
     *    clinics, so with 'assigned' a doctor repeats across clinics until the accountant switches him off.
     *  - a doctor switched 'off' is flagged `off` and listed last (the TV shows "absent today");
     *  - a move shifts up to `count` WAITING patients from one doctor to another inside a clinic. It is
     *    capped by the patients that are still waiting for the first doctor, so it fixes itself when the
     *    first doctor sees them. Clinic totals never change, only who they are waiting for.
     *
     * @param  array<string, string>  $off  "clinicCode|doctorCode" => 'on'|'off'
     * @param  array<int, array>    $moves  [clinic_code, from_doctor_code, to_doctor_code, count]
     */
    public function applyOverrides(array $clinics, array $off, array $moves): array
    {
        $movesByClinic = [];
        foreach ($moves as $m) {
            $movesByClinic[$m['clinic_code']][] = $m;
        }

        foreach ($clinics as &$clinic) {
            $clinic['moves'] = [];
            $byCode = [];
            foreach ($clinic['doctors'] as $i => $d) {
                $override = $off["{$clinic['code']}|{$d['code']}"] ?? null;
                $clinic['doctors'][$i]['override'] = $override;
                $clinic['doctors'][$i]['off'] = $override === 'off';
                $clinic['doctors'][$i]['moved_in'] = 0;
                $clinic['doctors'][$i]['moved_out'] = 0;
                $byCode[$d['code']] = $i;
            }

            foreach ($movesByClinic[$clinic['code']] ?? [] as $m) {
                $from = $byCode[trim($m['from_doctor_code'])] ?? null;
                $to = $byCode[trim($m['to_doctor_code'])] ?? null;
                if ($from === null || $to === null || $from === $to) {
                    continue;
                }
                $available = $clinic['doctors'][$from]['waiting'];
                if ($available === null) {
                    continue; // patient data unavailable: nothing to move
                }
                $n = min((int) $m['count'], max(0, (int) $available));
                $clinic['moves'][] = [
                    'from' => $clinic['doctors'][$from]['code'],
                    'to' => $clinic['doctors'][$to]['code'],
                    'from_name' => $clinic['doctors'][$from]['name'],
                    'to_name' => $clinic['doctors'][$to]['name'],
                    'count' => (int) $m['count'], // what the accountant asked for
                    'applied' => $n,              // what still counts right now
                ];
                if ($n === 0) {
                    continue;
                }
                $clinic['doctors'][$from]['waiting'] -= $n;
                $clinic['doctors'][$from]['booked'] -= $n;
                $clinic['doctors'][$from]['moved_out'] += $n;
                $clinic['doctors'][$to]['waiting'] += $n;
                $clinic['doctors'][$to]['booked'] += $n;
                $clinic['doctors'][$to]['moved_in'] += $n;
            }

            foreach ($clinic['doctors'] as $i => $d) {
                $active = ((int) ($d['booked'] ?? 0)) > 0 || $d['moved_in'] > 0;
                $clinic['doctors'][$i]['active'] = $active;
                // what a doctor is without any switch: shown for every assigned doctor, or only with patients
                $default = $active || config('tv.doctor_visibility') === 'assigned' || ! empty($d['migrated_from']);
                $clinic['doctors'][$i]['default_visible'] = $default;
                // a frozen / migrated-away doctor stays out of the TVs whatever the daily switch says
                $clinic['doctors'][$i]['visible'] = empty($d['rule']) && ($default || $d['override'] === 'on');
            }

            usort($clinic['doctors'], fn ($a, $b) => [(int) !$a['visible'], (int) $a['off'], $b['waiting'] ?? -1, $b['seen'] ?? -1, $a['name']]
                <=> [(int) !$b['visible'], (int) $b['off'], $a['waiting'] ?? -1, $a['seen'] ?? -1, $b['name']]);
        }
        unset($clinic);

        return $clinics;
    }

    /** @return array{0: array<string, array{rule:string, target:?string}>, 1: string} key "clinic|doctor" */
    private function loadRules(): array
    {
        try {
            $rules = [];
            foreach (DB::table('tv_doctor_rules')->get(['clinic_code', 'doctor_code', 'rule', 'target_clinic_code']) as $row) {
                $rules["{$row->clinic_code}|{$row->doctor_code}"] = ['rule' => $row->rule, 'target' => $row->target_clinic_code];
            }

            return [$rules, 'ok'];
        } catch (\Throwable $e) {
            Log::warning('[TvDashboard] doctor rules unavailable: '.$e->getMessage());

            return [[], 'unavailable'];
        }
    }

    /** Every stored rule (for the accountant's "frozen" and "migrated" lists), whatever the day. */
    public function listRules(): array
    {
        try {
            return DB::table('tv_doctor_rules')->orderBy('clinic_name')->orderBy('doctor_name')
                ->get(['clinic_code', 'doctor_code', 'rule', 'target_clinic_code', 'doctor_name', 'clinic_name', 'target_clinic_name', 'updated_by', 'updated_at'])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Permanent rules. Pure, so it can be tested.
     *  - frozen:   the doctor is flagged `rule = frozen` in that clinic (hidden from the TVs);
     *  - migrated: flagged `rule = migrated` (+ migrated_to) in the old clinic, and added to the new clinic
     *              (flagged migrated_from) when it is scheduled that day and does not list him already.
     *
     * @param  array<string, array{rule:string, target:?string}>  $rules  "clinicCode|doctorCode" => rule
     */
    public function applyRules(array $clinics, array $rules): array
    {
        $index = [];
        foreach ($clinics as $i => $c) {
            $index[$c['code']] = $i;
            foreach ($c['doctors'] as $j => $d) {
                $clinics[$i]['doctors'][$j] += ['rule' => null, 'migrated_to' => null, 'migrated_to_name' => null, 'migrated_from' => null, 'migrated_from_name' => null];
            }
        }

        foreach ($rules as $key => $r) {
            [$clinicCode, $doctorCode] = array_pad(explode('|', (string) $key, 2), 2, '');
            $ci = $index[$clinicCode] ?? null;
            if ($ci === null) {
                continue;
            }
            $di = null;
            foreach ($clinics[$ci]['doctors'] as $j => $d) {
                if ($d['code'] === $doctorCode) {
                    $di = $j;
                    break;
                }
            }
            if ($di === null) {
                continue;
            }

            if ($r['rule'] === 'frozen') {
                $clinics[$ci]['doctors'][$di]['rule'] = 'frozen';
            } elseif ($r['rule'] === 'migrated' && ! empty($r['target']) && $r['target'] !== $clinicCode) {
                $ti = $index[$r['target']] ?? null;
                $doctor = $clinics[$ci]['doctors'][$di];
                $clinics[$ci]['doctors'][$di]['rule'] = 'migrated';
                $clinics[$ci]['doctors'][$di]['migrated_to'] = $r['target'];
                $clinics[$ci]['doctors'][$di]['migrated_to_name'] = $ti === null ? null : $clinics[$ti]['name'];
                if ($ti === null) {
                    continue; // the new clinic does not run today: he simply is not shown here
                }
                $found = null;
                foreach ($clinics[$ti]['doctors'] as $j => $d) {
                    if ($d['code'] === $doctorCode) {
                        $found = $j;
                        break;
                    }
                }
                if ($found === null) {
                    $known = $clinics[$ti]['booked'] !== null;
                    $clinics[$ti]['doctors'][] = [
                        'code' => $doctor['code'], 'name' => $doctor['name'], 'qualification' => $doctor['qualification'] ?? null,
                        'assigned' => true, 'booked' => $known ? 0 : null, 'waiting' => $known ? 0 : null, 'seen' => $known ? 0 : null,
                        'rule' => null, 'migrated_to' => null, 'migrated_to_name' => null,
                        'migrated_from' => $clinicCode, 'migrated_from_name' => $clinics[$ci]['name'],
                    ];
                } elseif (empty($clinics[$ti]['doctors'][$found]['rule'])) {
                    $clinics[$ti]['doctors'][$found]['migrated_from'] = $clinicCode;
                    $clinics[$ti]['doctors'][$found]['migrated_from_name'] = $clinics[$ci]['name'];
                }
            }
        }

        return $clinics;
    }

    /** @param string|null $rule 'frozen' | 'migrated' | null (null = back to normal) */
    public function setRule(string $clinic, string $doctor, ?string $rule, ?string $target, array $names, ?string $actor): void
    {
        $key = ['clinic_code' => $clinic, 'doctor_code' => $doctor];
        if (in_array($rule, ['frozen', 'migrated'], true)) {
            DB::table('tv_doctor_rules')->updateOrInsert($key, [
                'rule' => $rule,
                'target_clinic_code' => $rule === 'migrated' ? $target : null,
                'doctor_name' => $names['doctor'] ?? null,
                'clinic_name' => $names['clinic'] ?? null,
                'target_clinic_name' => $rule === 'migrated' ? ($names['target'] ?? null) : null,
                'updated_by' => $actor, 'updated_at' => now(), 'created_at' => now(),
            ]);
        } else {
            DB::table('tv_doctor_rules')->where($key)->delete();
        }
        // a rule is not tied to a day: drop every cached day
        $today = Carbon::now(config('tv.timezone'));
        for ($d = -2; $d <= 15; $d++) {
            $this->forget($today->copy()->addDays($d)->format('Y-m-d'));
        }
    }

    /** @param string|null $state 'on' | 'off' | null (null = back to the default) */
    public function setDoctorState(string $date, string $clinic, string $doctor, ?string $state, ?string $actor): void
    {
        $key = ['date' => $date, 'clinic_code' => $clinic, 'doctor_code' => $doctor];
        if (in_array($state, ['on', 'off'], true)) {
            DB::table('tv_doctor_overrides')->updateOrInsert($key, ['state' => $state, 'updated_by' => $actor, 'updated_at' => now(), 'created_at' => now()]);
        } else {
            DB::table('tv_doctor_overrides')->where($key)->delete();
        }
        $this->forget($date);
    }

    public function setMove(string $date, string $clinic, string $from, string $to, int $count, ?string $actor): void
    {
        $key = ['date' => $date, 'clinic_code' => $clinic, 'from_doctor_code' => $from, 'to_doctor_code' => $to];
        if ($count > 0) {
            DB::table('tv_waiting_moves')->updateOrInsert($key, ['count' => $count, 'updated_by' => $actor, 'updated_at' => now(), 'created_at' => now()]);
        } else {
            DB::table('tv_waiting_moves')->where($key)->delete();
        }
        $this->forget($date);
    }

    /**
     * What a TV receives: only the doctors that are shown (see applyOverrides). Doctors that merely
     * appear in a clinic's assignment list are dropped, so a doctor never repeats across clinics
     * unless he really has patients in each of them.
     */
    public function forTv(array $payload): array
    {
        foreach ($payload['clinics'] as $i => $clinic) {
            $payload['clinics'][$i]['doctors'] = array_values(array_filter($clinic['doctors'], fn ($d) => $d['visible'] ?? false));
        }

        return $payload;
    }

    /** Count the accountant has stored for one from -> to pair. */
    public function storedMove(string $date, string $clinic, string $from, string $to): int
    {
        return (int) DB::table('tv_waiting_moves')->where([
            'date' => $date, 'clinic_code' => $clinic, 'from_doctor_code' => $from, 'to_doctor_code' => $to,
        ])->value('count');
    }

    /** Drop the cached response of a day so a change shows up on the next request. */
    public function forget(string $date): void
    {
        Cache::forget('tv:payload:'.str_replace('-', '', $date));
    }

    // ---------------------------------------------------------------------------------------------
    // Patient counts (API 3)
    // ---------------------------------------------------------------------------------------------

    /**
     * Pure aggregation of the raw visit rows into counters. Public so it can be unit tested.
     *
     * Rules (validated against the live API and the existing clinical dashboard):
     *  - one visit = clinicCode + mrNumber + visitNum + visitDate, duplicates are counted once
     *    (a completed copy wins);
     *  - consulted = visitStatus "C", or "V" when any of consDoctor, consDoctName, doctConsDt, doctConsTm
     *    has data; everything else (blank, "V" with all of them empty, ...) is still waiting for the
     *    doctor, except in the emergency clinic where a recorded consTime means consulted;
     *  - a waiting patient is attributed to the billing doctor (doctCode); a seen patient to the
     *    consulting doctor (consDoctor), or to the billing doctor when no consulting doctor is recorded.
     *
     * @return array<string, array{waiting:int, seen:int, doctors:array<string, array{name:string, waiting:int, seen:int}>}>
     */
    public function aggregate(array $rows): array
    {
        $completed = strtoupper((string) config('tv.completed_visit_status', 'C'));
        $emergency = strtoupper(trim((string) config('tv.emergency_clinic_name')));

        // 1. de-duplicate visits
        $visits = [];
        foreach ($rows as $r) {
            if (! is_array($r)) {
                continue;
            }
            $clinic = trim((string) ($r['clinicCode'] ?? ''));
            if ($clinic === '') {
                continue;
            }
            $key = implode('|', [$clinic, trim((string) ($r['mrNumber'] ?? '')), trim((string) ($r['visitNum'] ?? '')), trim((string) ($r['visitDate'] ?? ''))]);
            $isDone = $this->isCompleted($r, $completed, $emergency);
            if (! isset($visits[$key]) || ($isDone && ! $visits[$key]['done'])) {
                $visits[$key] = ['row' => $r, 'done' => $isDone];
            }
        }

        // 2. count
        $out = [];
        foreach ($visits as $v) {
            $r = $v['row'];
            $clinic = trim((string) $r['clinicCode']);
            $consDoctor = trim((string) ($r['consDoctor'] ?? ''));
            $billDoctor = trim((string) ($r['doctCode'] ?? ''));

            // consulted: the visit belongs to the consulting doctor (the billing doctor if none is recorded);
            // waiting: it belongs to the doctor it was billed to
            if ($v['done'] && $consDoctor !== '') {
                $code = $consDoctor;
                $name = $r['consDoctName'] ?? '';
            } else {
                $code = $billDoctor;
                $name = $r['billDoctName'] ?? '';
            }

            $out[$clinic] ??= ['waiting' => 0, 'seen' => 0, 'doctors' => []];
            $field = $v['done'] ? 'seen' : 'waiting';
            $out[$clinic][$field]++;

            if ($code !== '') {
                $out[$clinic]['doctors'][$code] ??= ['name' => $this->clean($name) ?: $code, 'waiting' => 0, 'seen' => 0];
                $out[$clinic]['doctors'][$code][$field]++;
            }
        }

        return $out;
    }

    private function isCompleted(array $r, string $completed, string $emergency): bool
    {
        $status = strtoupper(substr(trim((string) ($r['visitStatus'] ?? '')), 0, 1));
        if ($status === $completed) {
            return true;
        }


        if ($emergency !== ''
            && strtoupper(trim((string) ($r['clinicName'] ?? ''))) === $emergency
            && trim((string) ($r['consTime'] ?? '')) !== '') {
            return true;
        }

        // "V" (vitals, i.e. triage done) is consulted as soon as ANY consultation field has data: the
        // consulting doctor, his name, or the consultation date or time. With all of them empty the patient
        // is still waiting for the doctor.
        if (in_array($status, config('tv.consult_data_statuses', []), true)) {
            foreach (['consDoctor', 'consDoctName', 'doctConsDt', 'doctConsTm'] as $field) {
                if (trim((string) ($r[$field] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{0: ?array, 1: string, 2: ?int} [aggregated stats by clinicCode|null, state, as-of timestamp]
     */
    private function fetchPatientStats(string $ymd): array
    {
        $key = "tv:patients:{$ymd}";
        $lastKey = "{$key}:last";

        if (($hit = Cache::get($key)) !== null) {
            return [$hit['stats'], 'ok', $hit['at']];
        }

        try {
            $response = $this->http()->get(rtrim(config('tv.dashboard_url'), '/').'/'.$ymd);
            $json = $response->successful() ? $response->json() : null;

            if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data'])) {
                throw new \RuntimeException('unexpected response (HTTP '.$response->status().')');
            }

            $stats = $this->aggregate($json['data']); // raw rows are dropped when this method returns
            unset($json);
            $now = time();
            Cache::put($lastKey, ['stats' => $stats, 'at' => $now], 86400);
            // aggregated counters only, so an accountant's change can be recomputed instantly
            Cache::put($key, ['stats' => $stats, 'at' => $now], max(1, (int) config('tv.live_cache_seconds')));

            return [$stats, 'ok', $now];
        } catch (\Throwable $e) {
            Log::warning('[TvDashboard] patient data unavailable: '.$e->getMessage());
            $last = Cache::get($lastKey);
            if ($last && (time() - $last['at']) <= config('tv.max_stale_seconds')) {
                return [$last['stats'], 'stale', $last['at']];
            }

            return [null, 'unavailable', null];
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Schedule (API 2) and doctors (API 1) - cached, they are practically static
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array{0: ?array, 1: string}
     */
    private function fetchSchedule(int $weekday): array
    {
        $key = "tv:schedule:{$weekday}";
        if (($hit = Cache::get($key)) !== null) {
            return [$hit, 'ok'];
        }

        try {
            $response = $this->http()->get(rtrim(config('tv.clinic_weekdays_url'), '/').'/'.$weekday);
            $json = $response->successful() ? $response->json() : null;
            if (! is_array($json) || ! is_array($json['data'] ?? null)) {
                throw new \RuntimeException('unexpected response (HTTP '.$response->status().')');
            }

            $rows = [];
            foreach ($json['data'] as $r) {
                $code = trim((string) ($r['clinicCode'] ?? ''));
                if ($code === '' || isset($rows[$code])) {
                    continue;
                }
                $rows[$code] = [
                    'clinicCode' => $code,
                    'clinicName' => $r['clinicName'] ?? '',
                    'deptCode' => trim((string) ($r['deptCode'] ?? '')),
                    'deptName' => $r['deptName'] ?? '',
                    'floorCode' => $r['floorCode'] ?? '',
                ];
            }
            $rows = array_values($rows);

            Cache::put($key, $rows, config('tv.static_cache_seconds'));
            Cache::put("{$key}:last", $rows, 7 * 86400);

            return [$rows, 'ok'];
        } catch (\Throwable $e) {
            Log::warning('[TvDashboard] clinic schedule unavailable: '.$e->getMessage());
            $last = Cache::get("{$key}:last");

            return $last ? [$last, 'stale'] : [null, 'unavailable'];
        }
    }

    /**
     * Doctors of every clinic, fetched in small parallel batches and cached per clinic.
     *
     * @return array{0: array<string, array>, 1: int} [doctors by clinicCode, number of clinics that failed]
     */
    private function fetchDoctors(array $clinicCodes): array
    {
        $result = [];
        $missing = [];
        foreach ($clinicCodes as $code) {
            $hit = Cache::get("tv:doctors:{$code}");
            if ($hit !== null) {
                $result[$code] = $hit;
            } else {
                $missing[] = $code;
            }
        }

        $failed = 0;
        foreach (array_chunk($missing, 16) as $batch) {
            try {
                $responses = Http::pool(fn (Pool $pool) => array_map(
                    fn ($code) => $pool->as((string) $code)
                        ->withBasicAuth((string) config('tv.username'), (string) config('tv.password'))
                        ->acceptJson()
                        ->connectTimeout(config('tv.connect_timeout'))
                        ->timeout(config('tv.timeout'))
                        ->get(rtrim(config('tv.clinic_doctors_url'), '/').'/'.$code),
                    $batch
                ));
            } catch (\Throwable $e) {
                Log::warning('[TvDashboard] doctor lookup failed: '.$e->getMessage());
                $failed += count($batch);
                foreach ($batch as $code) {
                    $result[$code] = Cache::get("tv:doctors:{$code}:last");
                }

                continue;
            }

            foreach ($batch as $code) {
                $resp = $responses[(string) $code] ?? null;
                $json = ($resp instanceof \Illuminate\Http\Client\Response && $resp->successful()) ? $resp->json() : null;

                if (is_array($json) && is_array($json['data'] ?? null) && array_is_list($json['data'])) {
                    $list = $this->normaliseDoctors($json['data']);
                    Cache::put("tv:doctors:{$code}", $list, config('tv.static_cache_seconds'));
                    Cache::put("tv:doctors:{$code}:last", $list, 7 * 86400);
                    $result[$code] = $list;
                } elseif (is_array($json) && ($json['status'] ?? null) === 201) {
                    // "Clinic Not Found": a valid answer, the clinic simply has no doctors defined
                    Cache::put("tv:doctors:{$code}", [], config('tv.static_cache_seconds'));
                    $result[$code] = [];
                } else {
                    $failed++;
                    $result[$code] = Cache::get("tv:doctors:{$code}:last");
                }
            }
        }

        return [$result, $failed];
    }

    private function normaliseDoctors(array $rows): array
    {
        $doctors = [];
        foreach ($rows as $r) {
            $code = trim((string) ($r['doctCode'] ?? ''));
            if ($code === '' || isset($doctors[$code])) {
                continue;
            }
            $doctors[$code] = [
                'code' => $code,
                'name' => $this->clean($r['doctName'] ?? '') ?: $code,
                'qualification' => $this->clean($r['doctQuali'] ?? '') ?: null,
                'assigned' => true,
            ];
        }

        return array_values($doctors);
    }

    // ---------------------------------------------------------------------------------------------

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth((string) config('tv.username'), (string) config('tv.password'))
            ->acceptJson()
            ->connectTimeout(config('tv.connect_timeout'))
            ->timeout(config('tv.timeout'));
    }

    private function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
