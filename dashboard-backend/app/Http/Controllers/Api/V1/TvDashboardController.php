<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TvDashboardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public read-only endpoint for the TV dashboards. Returns aggregated counts only,
 * never patient-level data.
 */
class TvDashboardController extends Controller
{
    public function __construct(private readonly TvDashboardService $service)
    {
    }

    /**
     * GET /api/v1/tv/clinics-today
     *
     * Optional ?date=YYYY-MM-DD (today or earlier) to look at another day; the default is
     * today in the hospital's timezone, so the clinic list changes by itself at midnight.
     * Optional ?floor=001 keeps only the clinics of that floor (one TV per floor).
     */
    public function clinicsToday(Request $request): JsonResponse
    {
        $date = $request->query('date');

        if ($date !== null) {
            $valid = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && Carbon::hasFormat($date, 'Y-m-d');
            if (! $valid) {
                return response()->json(['status' => 'error', 'message' => 'date must be YYYY-MM-DD'], 422);
            }
            if ($date > Carbon::now(config('tv.timezone'))->format('Y-m-d')) {
                return response()->json(['status' => 'error', 'message' => 'date cannot be in the future'], 422);
            }
        }

        $floor = $request->query('floor');
        if ($floor !== null && (! is_string($floor) || ! preg_match('/^[A-Za-z0-9_-]{1,10}$/', $floor))) {
            return response()->json(['status' => 'error', 'message' => 'invalid floor'], 422);
        }

        // ?floor=001 : a TV registered to one floor only ever receives that floor's clinics
        $payload = $this->service->forTv($this->service->forFloor($this->service->build($date), $floor));

        return response()
            ->json($payload, $payload['status'] === 'success' ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
