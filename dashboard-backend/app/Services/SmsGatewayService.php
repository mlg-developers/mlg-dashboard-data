<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsGatewayService
{
    private string $apiKey;
    private string $secretKey;
    private string $senderId;
    private string $baseUrl = 'https://apisms.beem.africa/v1/send';

    public function __construct()
    {
        $this->apiKey   = config('sms.beem_api_key', '');
        $this->secretKey = config('sms.beem_secret_key', '');
        $this->senderId  = config('sms.sender_id', 'MNH');
    }

    public function send(string $phone, string $message): array
    {
        $phone = $this->normalizePhone($phone);

        if (empty($this->apiKey) || empty($this->secretKey)) {
            Log::warning('[SMS] Gateway not configured — simulating send', ['phone' => $phone]);
            return ['success' => true, 'simulated' => true];
        }

        try {
            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(15)
                ->post($this->baseUrl, [
                    'source_addr' => $this->senderId,
                    'encoding'    => 0,
                    'message'     => $message,
                    'recipients'  => [['recipient_id' => 1, 'dest_addr' => $phone]],
                ]);

            if ($response->successful()) {
                $body = $response->json();
                $code = $body['code'] ?? null;
                if ($code === 100 || $code === 'DP_SUB_OK') {
                    return ['success' => true];
                }
                return ['success' => false, 'error' => $body['message'] ?? 'Gateway rejected'];
            }

            return ['success' => false, 'error' => 'HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            Log::error('[SMS] Send failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '255' . substr($phone, 1);
        } elseif (str_starts_with($phone, '7') && strlen($phone) === 9) {
            $phone = '255' . $phone;
        }
        return $phone;
    }
}
