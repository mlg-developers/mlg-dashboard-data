<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsGatewayService
{
    private string $apiKey;
    private string $apiSecret;
    private string $senderId;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey    = config('sms.api_key', '');
        $this->apiSecret = config('sms.api_secret', '');
        $this->senderId  = config('sms.sender_id', 'MLOGANZILA');
        $this->baseUrl   = rtrim(config('sms.base_url', 'https://messaging.kilakona.co.tz/api/v1/vendor/message'), '/');
    }

    /**
     * Send SMS to one or many recipients.
     * Returns ['success' => bool, 'shoot_id' => string|null, 'error' => string|null]
     */
    public function sendBulk(array $phones, string $message, ?string $callbackUrl = null): array
    {
        $phones = array_map([$this, 'normalizePhone'], $phones);
        $contacts = implode(',', array_filter($phones));

        if (empty($this->apiKey) || empty($this->apiSecret)) {
            Log::warning('[SMS] Gateway not configured — simulating bulk send', ['count' => count($phones)]);
            return ['success' => true, 'shoot_id' => 'SIM-' . uniqid(), 'simulated' => true];
        }

        $payload = [
            'senderId'    => $this->senderId,
            'messageType' => 'text',
            'message'     => $message,
            'contacts'    => $contacts,
        ];

        if ($callbackUrl) {
            $payload['deliveryReportUrl'] = $callbackUrl;
        }

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'api_key'      => $this->apiKey,
                'api_secret'   => $this->apiSecret,
            ])
            ->timeout(30)
            ->post("{$this->baseUrl}/send", $payload);

            $body = $response->json();

            Log::info('[SMS] Kilakona send response', ['status' => $response->status(), 'body' => $body]);

            if ($response->successful()) {
                $shootId = $body['shootId'] ?? $body['shoot_id'] ?? $body['data']['shootId'] ?? null;
                return ['success' => true, 'shoot_id' => $shootId];
            }

            $error = $body['message'] ?? $body['error'] ?? ('HTTP ' . $response->status());
            return ['success' => false, 'shoot_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::error('[SMS] Kilakona send exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'shoot_id' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch delivery report for a given shootId.
     */
    public function deliveryReport(string $shootId): array
    {
        try {
            $response = Http::withHeaders([
                'api_key'    => $this->apiKey,
                'api_secret' => $this->apiSecret,
            ])
            ->timeout(15)
            ->get("{$this->baseUrl}/deliver/{$shootId}");

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error('[SMS] Delivery report error', ['shootId' => $shootId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Check SMS balance.
     */
    public function balance(): array
    {
        if (empty($this->apiKey) || empty($this->apiSecret)) {
            return ['success' => false, 'error' => 'Gateway not configured'];
        }

        try {
            $response = Http::withHeaders([
                'api_key'    => $this->apiKey,
                'api_secret' => $this->apiSecret,
            ])
            ->timeout(15)
            ->get("{$this->baseUrl}/balance");

            $body = $response->json();
            return ['success' => $response->successful(), 'data' => $body];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '255' . substr($phone, 1);
        } elseif (strlen($phone) === 9 && preg_match('/^[67]/', $phone)) {
            $phone = '255' . $phone;
        }
        return $phone;
    }
}
