<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaignSmsJob;
use App\Models\SmsCampaign;
use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;

class SmsController extends Controller
{
    public function send(Request $request)
    {
        $v = Validator::make($request->all(), [
            'title'        => 'required|string|max:255',
            'message'      => 'required|string|max:918',
            'recipients'   => 'required|array|min:1|max:5000',
            'recipients.*.phone' => 'required|string',
            'recipients.*.name'  => 'nullable|string|max:100',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $campaign = SmsCampaign::create([
            'title'             => $request->title,
            'message'           => $request->message,
            'total_recipients'  => count($request->recipients),
            'status'            => 'sending',
            'created_by'        => $request->user()->id,
        ]);

        $jobs = [];
        foreach ($request->recipients as $r) {
            $log = SmsLog::create([
                'campaign_id'    => $campaign->id,
                'recipient_name' => $r['name'] ?? null,
                'phone'          => $r['phone'],
                'status'         => 'pending',
            ]);
            $jobs[] = new SendCampaignSmsJob($log->id);
        }

        Bus::batch($jobs)
            ->name("SMS Campaign #{$campaign->id}: {$campaign->title}")
            ->allowFailures()
            ->dispatch();

        return response()->json([
            'status'      => 'queued',
            'campaign_id' => $campaign->id,
            'total'       => $campaign->total_recipients,
        ]);
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
        $campaign = SmsCampaign::where('created_by', $request->user()->id)
            ->findOrFail($id);

        $logs = SmsLog::where('campaign_id', $campaign->id)
            ->orderByDesc('id')
            ->paginate(100);

        return response()->json([
            'campaign' => $campaign,
            'logs'     => $logs,
        ]);
    }

    public function status(Request $request, int $id)
    {
        $campaign = SmsCampaign::where('created_by', $request->user()->id)
            ->findOrFail($id);

        return response()->json([
            'campaign_id'     => $campaign->id,
            'status'          => $campaign->status,
            'total'           => $campaign->total_recipients,
            'sent'            => $campaign->sent_count,
            'delivered'       => $campaign->delivered_count,
            'failed'          => $campaign->failed_count,
            'pending'         => $campaign->total_recipients - $campaign->delivered_count - $campaign->failed_count,
        ]);
    }
}
