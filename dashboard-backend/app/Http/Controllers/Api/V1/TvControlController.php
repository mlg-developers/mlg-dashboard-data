<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TvDashboardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accountant controls for the TV dashboards (login required, see routes).
 * Works with counts only: patients are never listed or identified here.
 */
class TvControlController extends Controller
{
    private const MAX_DAYS_AHEAD = 14;

    public function __construct(private readonly TvDashboardService $service)
    {
    }

    /** GET /api/v1/tv/control/overview?date=YYYY-MM-DD  (same data as the TV, plus switches and moves) */
    public function overview(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }
        $date = $this->dateOrToday($request->query('date'));
        if ($date === null) {
            return $this->error('date must be YYYY-MM-DD and at most '.self::MAX_DAYS_AHEAD.' days ahead', 422);
        }

        return response()->json($this->service->build($date) + ['rules' => $this->service->listRules()])->header('Cache-Control', 'no-store');
    }

    /** PUT /api/v1/tv/control/doctor  {date, clinic_code, doctor_code, state: "on" | "off" | null} */
    public function doctor(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'clinic_code' => ['required', 'string', 'max:20'],
            'doctor_code' => ['required', 'string', 'max:40'],
            'state' => ['present', 'nullable', 'in:on,off'],
        ]);
        if ($this->dateOrToday($data['date']) === null) {
            return $this->error('date is out of range', 422);
        }
        [$clinic, $doctor] = $this->find($data['date'], $data['clinic_code'], $data['doctor_code']);
        if (! $clinic || ! $doctor) {
            return $this->error('clinic or doctor not found for that day', 404);
        }

        $this->service->setDoctorState($data['date'], $data['clinic_code'], $data['doctor_code'], $data['state'], $this->actor($request));

        return $this->saved($data['date'], $data['clinic_code']);
    }

    /**
     * PUT /api/v1/tv/control/rule  {date, clinic_code, doctor_code, rule: "frozen" | "migrated" | null, target_clinic_code?}
     * Permanent (not tied to the day): the day is only used to find the doctor and clinics.
     */
    public function rule(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'clinic_code' => ['required', 'string', 'max:20'],
            'doctor_code' => ['required', 'string', 'max:40'],
            'rule' => ['present', 'nullable', 'in:frozen,migrated'],
            'target_clinic_code' => ['required_if:rule,migrated', 'nullable', 'string', 'max:20', 'different:clinic_code'],
        ]);
        if ($this->dateOrToday($data['date']) === null) {
            return $this->error('date is out of range', 422);
        }
        // restoring a doctor (rule null) works on any day, even when his clinic does not run on the viewed day
        if ($data['rule'] === null) {
            $this->service->setRule($data['clinic_code'], $data['doctor_code'], null, null, [], $this->actor($request));

            return response()->json([
                'status' => 'success',
                'clinics' => array_values(array_filter([$this->find($data['date'], $data['clinic_code'], '')[0]])),
                'rules' => $this->service->listRules(),
            ]);
        }
        [$clinic, $doctor] = $this->find($data['date'], $data['clinic_code'], $data['doctor_code']);
        if (! $clinic || ! $doctor) {
            return $this->error('clinic or doctor not found for that day', 404);
        }
        $target = null;
        if ($data['rule'] === 'migrated') {
            [$target] = $this->find($data['date'], $data['target_clinic_code'], '');
            if (! $target) {
                return $this->error('the new clinic is not running on that day', 422);
            }
        }

        $this->service->setRule(
            $data['clinic_code'], $data['doctor_code'], $data['rule'], $data['target_clinic_code'] ?? null,
            ['doctor' => $doctor['name'], 'clinic' => $clinic['name'], 'target' => $target['name'] ?? null],
            $this->actor($request),
        );

        $clinics = [$this->find($data['date'], $data['clinic_code'], '')[0]];
        if ($target) {
            $clinics[] = $this->find($data['date'], $data['target_clinic_code'], '')[0];
        }

        return response()->json(['status' => 'success', 'clinics' => $clinics, 'rules' => $this->service->listRules()]);
    }

    /** PUT /api/v1/tv/control/move  {date, clinic_code, from_doctor_code, to_doctor_code, count}  (count 0 = undo) */
    public function move(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'clinic_code' => ['required', 'string', 'max:20'],
            'from_doctor_code' => ['required', 'string', 'max:40'],
            'to_doctor_code' => ['required', 'string', 'max:40', 'different:from_doctor_code'],
            'count' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);
        if ($this->dateOrToday($data['date']) === null) {
            return $this->error('date is out of range', 422);
        }

        [$clinic, $from] = $this->find($data['date'], $data['clinic_code'], $data['from_doctor_code']);
        [, $to] = $this->find($data['date'], $data['clinic_code'], $data['to_doctor_code']);
        if (! $clinic || ! $from || ! $to) {
            return $this->error('clinic or doctor not found for that day', 404);
        }

        $count = (int) $data['count'];
        if ($count > 0) {
            if ($from['waiting'] === null) {
                return $this->error('patient data is unavailable right now, try again shortly', 503);
            }
            // any doctor assigned to the clinic can receive patients (he then shows up on the TV); only a
            // doctor switched off for the day cannot
            if (! empty($to['off'])) {
                return $this->error('the receiving doctor is switched off today', 422);
            }
            if (! empty($to['rule']) || ! empty($from['rule'])) {
                return $this->error('a frozen or migrated doctor cannot take part in a move', 422);
            }
            $stored = $this->service->storedMove($data['date'], $data['clinic_code'], $data['from_doctor_code'], $data['to_doctor_code']);
            $max = (int) $from['waiting'] + min($stored, (int) $from['moved_out']);
            if ($count > $max) {
                return $this->error("only {$max} waiting patient(s) can be moved from this doctor", 422);
            }
        }

        $this->service->setMove($data['date'], $data['clinic_code'], $data['from_doctor_code'], $data['to_doctor_code'], $count, $this->actor($request));

        return $this->saved($data['date'], $data['clinic_code']);
    }

    // ---------------------------------------------------------------------------------------------

    /** Success answer carrying the recomputed clinic, so the page can update it in place. */
    private function saved(string $date, string $clinicCode): JsonResponse
    {
        [$clinic] = $this->find($date, $clinicCode, '');

        return response()->json(['status' => 'success', 'clinic' => $clinic]);
    }

    private function deny(Request $request): ?JsonResponse
    {
        $role = (string) optional($request->user())->role;
        $allowed = config('tv.control_roles', []);

        return in_array($role, $allowed, true)
            ? null
            : $this->error('your role is not allowed to control the TV dashboards', 403);
    }

    /** @return array{0: ?array, 1: ?array} [clinic, doctor] from the day's computed data */
    private function find(string $date, string $clinicCode, string $doctorCode): array
    {
        $payload = $this->service->build($date);
        foreach ($payload['clinics'] ?? [] as $clinic) {
            if ($clinic['code'] === $clinicCode) {
                foreach ($clinic['doctors'] as $doctor) {
                    if ($doctor['code'] === $doctorCode) {
                        return [$clinic, $doctor];
                    }
                }

                return [$clinic, null];
            }
        }

        return [null, null];
    }

    private function dateOrToday(?string $date): ?string
    {
        $tz = config('tv.timezone');
        if ($date === null || $date === '') {
            return Carbon::now($tz)->format('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! Carbon::hasFormat($date, 'Y-m-d')) {
            return null;
        }
        $limit = Carbon::now($tz)->addDays(self::MAX_DAYS_AHEAD)->format('Y-m-d');

        return $date <= $limit ? $date : null;
    }

    private function actor(Request $request): ?string
    {
        $user = $request->user();

        return $user ? (string) ($user->name ?: $user->email) : null;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
