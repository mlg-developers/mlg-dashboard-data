<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TvDashboardService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TvControlRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        foreach (['2026_11_25_100000_create_tv_control_tables.php', '2026_11_26_100000_create_tv_doctor_rules_table.php'] as $file) {
            (require database_path("migrations/{$file}"))->up();
        }
        $clinics = [
            ['code' => '1', 'name' => 'ONE', 'waiting' => 0, 'seen' => 0, 'booked' => 0, 'floor_code' => null, 'floor_name' => null, 'doctors' => [
                ['code' => 'A', 'name' => 'Doc A', 'qualification' => 'SPECIALIST', 'assigned' => true, 'booked' => 0, 'waiting' => 0, 'seen' => 0]]],
            ['code' => '2', 'name' => 'TWO', 'waiting' => 0, 'seen' => 0, 'booked' => 0, 'floor_code' => null, 'floor_name' => null, 'doctors' => []],
        ];
        $svc = $this->partialMock(TvDashboardService::class, fn ($m) => $m->shouldReceive('build')->andReturnUsing(function () use ($clinics) {
            $real = new TvDashboardService();
            [$r] = (fn () => $this->loadRules())->call($real);

            return ['clinics' => $real->applyOverrides($real->applyRules($clinics, $r), [], []), 'totals' => [], 'floors' => []];
        }));
        $this->app->instance(TvDashboardService::class, $svc);
    }

    private function actingAs_(string $role): void
    {
        $user = new User(['name' => 'Acc', 'email' => 'a@x.test']);
        $user->role = $role;
        Sanctum::actingAs($user);
    }

    public function test_freeze_migrate_and_restore_are_stored_permanently(): void
    {
        $this->actingAs_('Accountant');
        $day = now(config('tv.timezone'))->format('Y-m-d');
        $base = ['date' => $day, 'clinic_code' => '1', 'doctor_code' => 'A'];

        $this->putJson('/api/v1/tv/control/rule', $base + ['rule' => 'frozen'])->assertOk();
        $this->assertSame('frozen', DB::table('tv_doctor_rules')->value('rule'));

        $this->putJson('/api/v1/tv/control/rule', $base + ['rule' => 'migrated'])->assertStatus(422);
        $this->putJson('/api/v1/tv/control/rule', $base + ['rule' => 'migrated', 'target_clinic_code' => '2'])->assertOk();
        $row = DB::table('tv_doctor_rules')->first();
        $this->assertSame(['migrated', '2', 'Doc A', 'TWO'], [$row->rule, $row->target_clinic_code, $row->doctor_name, $row->target_clinic_name]);
        $this->assertSame(1, DB::table('tv_doctor_rules')->count());

        $this->putJson('/api/v1/tv/control/rule', $base + ['rule' => null])->assertOk();
        $this->assertSame(0, DB::table('tv_doctor_rules')->count());
    }

    public function test_other_roles_are_refused(): void
    {
        $this->actingAs_('Nurse');
        $this->putJson('/api/v1/tv/control/rule', ['date' => now()->format('Y-m-d'), 'clinic_code' => '1', 'doctor_code' => 'A', 'rule' => 'frozen'])->assertStatus(403);
    }
}
