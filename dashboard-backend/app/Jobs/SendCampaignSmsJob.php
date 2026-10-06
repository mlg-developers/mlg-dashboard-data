<?php

namespace App\Jobs;

use App\Models\SmsCampaign;
use App\Models\SmsLog;
use App\Services\SmsGatewayService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignSmsJob implements ShouldQueue
{
    use Batchable, Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public int $logId,
    ) {}

    public function handle(SmsGatewayService $gateway): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $log = SmsLog::find($this->logId);
        if (!$log) return;

        $campaign = SmsCampaign::find($log->campaign_id);
        if (!$campaign) return;

        $result = $gateway->send($log->phone, $campaign->message);

        if ($result['success']) {
            $log->update(['status' => 'sent', 'sent_at' => now()]);
            $campaign->increment('sent_count');
            $campaign->increment('delivered_count');
        } else {
            $log->update(['status' => 'failed', 'error_message' => $result['error'] ?? 'Unknown error']);
            $campaign->increment('failed_count');
        }

        $campaign->increment('sent_count', 0); // refresh
        $total = $campaign->delivered_count + $campaign->failed_count;
        if ($total >= $campaign->total_recipients) {
            $campaign->update(['status' => 'completed']);
        }
    }
}
