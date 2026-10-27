<?php

namespace App\Services;

use App\Contracts\SmsGateway;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MramSmsGateway implements SmsGateway
{
    private const TRUSTED_HOST = 'msg.mram.com.bd';

    private const VALID_MODES = ['test', 'live'];

    private const DOCUMENTED_NUMERIC_RESPONSES = [
        '1002' => ['status' => 'rejected', 'error_code' => 'sms_provider_sender_id_not_found'],
        '1003' => ['status' => 'rejected', 'error_code' => 'sms_provider_api_not_found'],
        '1004' => ['status' => 'rejected', 'error_code' => 'sms_provider_spam_detected'],
        '1005' => ['status' => 'unknown', 'error_code' => 'sms_provider_internal_error_1005'],
        '1006' => ['status' => 'unknown', 'error_code' => 'sms_provider_internal_error_1006'],
        '1007' => ['status' => 'rejected', 'error_code' => 'sms_provider_balance_insufficient'],
        '1008' => ['status' => 'rejected', 'error_code' => 'sms_provider_message_empty'],
        '1009' => ['status' => 'rejected', 'error_code' => 'sms_provider_message_type_missing'],
        '1010' => ['status' => 'rejected', 'error_code' => 'sms_provider_invalid_credentials'],
        '1011' => ['status' => 'rejected', 'error_code' => 'sms_provider_invalid_user_id'],
        '1012' => ['status' => 'rejected', 'error_code' => 'sms_provider_invalid_number'],
        '1013' => ['status' => 'rejected', 'error_code' => 'sms_provider_rate_limited'],
        '1014' => ['status' => 'rejected', 'error_code' => 'sms_provider_template_not_found'],
        '1015' => ['status' => 'rejected', 'error_code' => 'sms_provider_content_validation_failed'],
        '1016' => ['status' => 'rejected', 'error_code' => 'sms_provider_ip_not_allowed'],
        '1019' => ['status' => 'rejected', 'error_code' => 'sms_provider_purpose_missing'],
    ];

    public function send(string $phone, string $message): array
    {
        $config = config('services.mram_sms');
        if (! ($config['enabled'] ?? false)) {
            return ['status' => 'unavailable', 'error_code' => 'sms_disabled'];
        }

        $mode = strtolower(trim((string) ($config['mode'] ?? '')));
        if (! in_array($mode, self::VALID_MODES, true)) {
            return ['status' => 'unavailable', 'error_code' => 'sms_provider_invalid_mode'];
        }

        $credentials = $config['credentials'][$mode] ?? [];
        if (blank($credentials['api_key'] ?? null) || blank($credentials['sender_id'] ?? null)) {
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
                'api_key' => $credentials['api_key'],
                'type' => 'text',
                'contacts' => $normalizedPhone,
                'senderid' => $credentials['sender_id'],
                'msg' => $message,
                'label' => 'transactional',
            ]);
        } catch (\Throwable) {
            // A network timeout has an ambiguous outcome. Never retry credential SMS automatically.
            return ['status' => 'unknown', 'error_code' => 'sms_provider_network_unknown'];
        }

        $this->logResponseDiagnostics($response);

        return $this->interpretResponse($response->status(), $response->body());
    }

    private function client(): PendingRequest
    {
        return Http::asForm()->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(10);
    }

    private function trustedUrl(?string $baseUrl): ?string
    {
        if (blank($baseUrl)) {
            return null;
        }
        $parts = parse_url($baseUrl);
        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== self::TRUSTED_HOST
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        return 'https://' . self::TRUSTED_HOST . '/smsapi';
    }

    private function logResponseDiagnostics(Response $response): void
    {
        if (! app()->environment(['local', 'testing']) || ! config('services.mram_sms.response_diagnostics', false)) {
            return;
        }

        $body = $response->body();
        $trimmed = trim($body);
        $recognizedNumericErrorCode = preg_match('/^\d+$/', $trimmed) === 1
            && array_key_exists($trimmed, self::DOCUMENTED_NUMERIC_RESPONSES)
            ? $trimmed
            : null;

        Log::debug('MRAM SMS response diagnostic', [
            'correlation_id' => (string) Str::uuid(),
            'http_status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'body_length' => strlen($body),
            'response_format' => $this->responseFormat($body),
            'recognized_numeric_error_code' => $recognizedNumericErrorCode,
        ]);
    }

    private function responseFormat(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return 'empty';
        }
        if (preg_match('/^\d+$/', $trimmed) === 1) {
            return 'numeric';
        }
        json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return 'json';
        }
        if (preg_match('//u', $body) === 1) {
            return 'plain_text';
        }

        return 'other';
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

        if (preg_match('/^\d+$/', $trimmed) === 1 && array_key_exists($trimmed, self::DOCUMENTED_NUMERIC_RESPONSES)) {
            return self::DOCUMENTED_NUMERIC_RESPONSES[$trimmed];
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
