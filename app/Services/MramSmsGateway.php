<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MramSmsGateway implements SmsGateway
{
    private const TRUSTED_HOST = 'msg.mram.com.bd';

    public function send(string $phone, string $message): array
    {
        $config = config('services.mram_sms');
        if (! ($config['enabled'] ?? false)) {
            return ['status' => 'unavailable', 'error_code' => 'sms_disabled'];
        }
        if (blank($config['api_key'] ?? null) || blank($config['sender_id'] ?? null)) {
            return ['status' => 'unavailable', 'error_code' => 'sms_provider_configuration_missing'];
        }

        $normalizedPhone = $this->normalizePhone($phone);
        if ($normalizedPhone === null) {
            return ['status' => 'rejected', 'error_code' => 'invalid_phone_number'];
        }

        $url = $this->trustedUrl($config['base_url'] ?? null);
        if ($url === null) {
            return ['status' => 'unavailable', 'error_code' => 'sms_provider_host_not_trusted'];
        }

        try {
            $response = $this->client()->post($url, [
                'api_key' => $config['api_key'],
                'type' => 'text',
                'contacts' => $normalizedPhone,
                'senderid' => $config['sender_id'],
                'msg' => $message,
                'label' => 'transactional',
            ]);
        } catch (\Throwable) {
            // A network timeout has an ambiguous outcome. Never retry credential SMS automatically.
            return ['status' => 'unknown', 'error_code' => 'sms_provider_network_unknown'];
        }

        return $this->interpretResponse($response->status(), $response->body());
    }

    private function client(): PendingRequest
    {
        return Http::asForm()->acceptJson()->connectTimeout(5)->timeout(10);
    }

    private function trustedUrl(?string $baseUrl): ?string
    {
        if (blank($baseUrl)) {
            return null;
        }
        $parts = parse_url(rtrim($baseUrl, '/'));
        if (($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== self::TRUSTED_HOST || isset($parts['user'], $parts['pass'], $parts['port'])) {
            return null;
        }

        return rtrim($baseUrl, '/').'/smsapi';
    }

    private function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($phone));
        if ($digits === null) {
            return null;
        }
        if (str_starts_with($digits, '01') && strlen($digits) === 11) {
            $digits = '880'.substr($digits, 1);
        }
        if (! preg_match('/^8801[3-9]\d{8}$/', $digits)) {
            return null;
        }

        return $digits;
    }

    private function interpretResponse(int $status, string $body): array
    {
        $trimmed = trim($body);
        if ($status === 401 || $status === 403) {
            return ['status' => 'rejected', 'error_code' => 'sms_provider_authentication_failed'];
        }
        if ($status === 429) {
            return ['status' => 'rejected', 'error_code' => 'sms_provider_rate_limited'];
        }
        if ($status >= 400 && $status < 500) {
            return ['status' => 'rejected', 'error_code' => 'sms_provider_request_rejected'];
        }
        if ($status >= 500) {
            return ['status' => 'unknown', 'error_code' => 'sms_provider_server_unknown'];
        }
        if ($status < 200 || $status >= 300) {
            return ['status' => 'unknown', 'error_code' => 'sms_provider_http_unknown'];
        }

        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            $statusValue = strtolower((string) ($json['status'] ?? $json['result'] ?? ''));
            if (in_array($statusValue, ['ok', 'success', 'accepted'], true)) {
                return ['status' => 'accepted', 'provider_reference' => $json['id'] ?? $json['message_id'] ?? null];
            }
            if (array_key_exists('error', $json) || in_array($statusValue, ['error', 'failed', 'rejected'], true)) {
                return ['status' => 'rejected', 'error_code' => 'sms_provider_response_rejected'];
            }
        }
        if (preg_match('/^OK(?:\b|:)/i', $trimmed) === 1) {
            return ['status' => 'accepted', 'provider_reference' => null];
        }
        if (preg_match('/^ERROR(?:\b|:)/i', $trimmed) === 1) {
            return ['status' => 'rejected', 'error_code' => 'sms_provider_response_rejected'];
        }

        return ['status' => 'unknown', 'error_code' => 'sms_provider_response_unknown'];
    }
}
