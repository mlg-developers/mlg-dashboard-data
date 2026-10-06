<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Services\SmsGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SmsController extends Controller
{
    public function __construct(private SmsGatewayService $gateway) {}

    /**
     * Send a campaign — bulk send via Kilakona (one API call for all recipients).
     */
    public function send(Request $request)
    {
        $v = Validator::make($request->all(), [
            'title'              => 'required|string|max:255',
            'message'            => 'required|string|max:918',
            'recipients'         => 'required|array|min:1|max:5000',
            'recipients.*.phone' => 'required|string',
            'recipients.*.name'  => 'nullable|string|max:100',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $campaign = SmsCampaign::create([
            'title'            => $request->title,
            'message'          => $request->message,
            'total_recipients' => count($request->recipients),
            'status'           => 'sending',
            'created_by'       => $request->user()->id,
        ]);

        // Create per-recipient log entries
        foreach ($request->recipients as $r) {
            SmsLog::create([
                'campaign_id'    => $campaign->id,
                'recipient_name' => $r['name'] ?? null,
                'phone'          => $r['phone'],
                'status'         => 'pending',
            ]);
        }

        $phones      = collect($request->recipients)->pluck('phone')->toArray();
        $callbackUrl = url("/api/v1/sms/delivery-callback/{$campaign->id}");

        $result = $this->gateway->sendBulk($phones, $request->message, $callbackUrl);

        if ($result['success']) {
            $shootId = $result['shoot_id'] ?? null;
            $simulated = $result['simulated'] ?? false;

            $campaign->update([
                'shoot_id'       => $shootId,
                'status'         => 'completed',
                'sent_count'     => $campaign->total_recipients,
                'delivered_count'=> $simulated ? $campaign->total_recipients : 0,
            ]);

            SmsLog::where('campaign_id', $campaign->id)
                ->update(['status' => $simulated ? 'delivered' : 'sent', 'sent_at' => now()]);

            return response()->json([
                'status'      => 'sent',
                'campaign_id' => $campaign->id,
                'shoot_id'    => $shootId,
                'total'       => $campaign->total_recipients,
                'simulated'   => $simulated,
            ]);
        }

        // Gateway failure
        $campaign->update([
            'status'       => 'failed',
            'failed_count' => $campaign->total_recipients,
        ]);
        SmsLog::where('campaign_id', $campaign->id)
            ->update(['status' => 'failed', 'error_message' => $result['error'] ?? 'Gateway error']);

        return response()->json([
            'status'  => 'error',
            'message' => $result['error'] ?? 'SMS gateway error.',
        ], 502);
    }

    /**
     * Delivery status callback from Kilakona (public, no auth).
     */
    public function deliveryCallback(Request $request, int $campaignId)
    {
        $campaign = SmsCampaign::find($campaignId);
        if (!$campaign) return response()->json(['ok' => false], 404);

        $data = $request->all();

        // Kilakona sends status per phone — field names may vary
        $phone  = $data['msisdn'] ?? $data['phone'] ?? $data['recipient'] ?? null;
        $status = strtolower($data['status'] ?? $data['deliveryStatus'] ?? '');

        if ($phone) {
            $normalized = preg_replace('/\D/', '', $phone);
            $log = SmsLog::where('campaign_id', $campaignId)
                ->where('phone', 'like', "%{$normalized}")
                ->first();

            if ($log) {
                $mapped = match(true) {
                    str_contains($status, 'deliver') => 'delivered',
                    str_contains($status, 'fail')    => 'failed',
                    default                          => 'sent',
                };
                $log->update(['status' => $mapped]);

                $delivered = SmsLog::where('campaign_id', $campaignId)->where('status', 'delivered')->count();
                $failed    = SmsLog::where('campaign_id', $campaignId)->where('status', 'failed')->count();
                $campaign->update([
                    'delivered_count' => $delivered,
                    'failed_count'    => $failed,
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function campaigns(Request $request)
    {
        $campaigns = SmsCampaign::where('created_by', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($campaigns);
    }

    public function campaignLogs(Request $request, int $id)
    {
        $campaign = SmsCampaign::where('created_by', $request->user()->id)->findOrFail($id);

        $logs = SmsLog::where('campaign_id', $campaign->id)
            ->orderByDesc('id')
            ->paginate(100);

        return response()->json(['campaign' => $campaign, 'logs' => $logs]);
    }

    public function status(Request $request, int $id)
    {
        $campaign = SmsCampaign::where('created_by', $request->user()->id)->findOrFail($id);

        // Optionally pull live delivery report from Kilakona if shoot_id exists
        if ($campaign->shoot_id && $campaign->status !== 'completed') {
            $report = $this->gateway->deliveryReport($campaign->shoot_id);
            if (!empty($report)) {
                // Update counts if gateway returns summary
                $delivered = $report['delivered'] ?? $report['deliveredCount'] ?? null;
                $failed    = $report['failed']    ?? $report['failedCount']    ?? null;
                if ($delivered !== null) $campaign->update(['delivered_count' => (int)$delivered]);
                if ($failed    !== null) $campaign->update(['failed_count'    => (int)$failed]);
                $campaign->refresh();
            }
        }

        return response()->json([
            'campaign_id' => $campaign->id,
            'status'      => $campaign->status,
            'total'       => $campaign->total_recipients,
            'sent'        => $campaign->sent_count,
            'delivered'   => $campaign->delivered_count,
            'failed'      => $campaign->failed_count,
            'pending'     => max(0, $campaign->total_recipients - $campaign->delivered_count - $campaign->failed_count),
            'shoot_id'    => $campaign->shoot_id,
        ]);
    }

    public function balance()
    {
        $result = $this->gateway->balance();

        if (!($result['success'] ?? false)) {
            return response()->json(['success' => false, 'balance' => null, 'error' => $result['error'] ?? 'Gateway error'], 502);
        }

        $body    = $result['data'] ?? [];
        // Kilakona returns: { code, success, message, data: { totalSms: N } }
        $balance = $body['data']['totalSms'] ?? $body['data']['balance'] ?? $body['data']['credits']
                ?? $body['balance'] ?? $body['credits'] ?? $body['sms_balance'] ?? null;

        return response()->json([
            'success' => true,
            'balance' => $balance,
            'raw'     => $body,
        ]);
    }
}
