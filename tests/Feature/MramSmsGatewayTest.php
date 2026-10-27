<?php

namespace Tests\Feature;

use App\Services\MramSmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class MramSmsGatewayTest extends TestCase
{
    private MramSmsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = app(MramSmsGateway::class);
        config(['services.mram_sms' => [
            'enabled' => true,
            'response_diagnostics' => false,
            'mode' => 'test',
            'base_url' => 'https://msg.mram.com.bd',
            'credentials' => [
                'test' => ['api_key' => 'test-key-only', 'sender_id' => 'TEST-SENDER'],
                'live' => ['api_key' => 'live-key-only', 'sender_id' => 'LIVE-SENDER'],
            ],
        ]]);
    }

    public function test_successful_provider_acceptance_is_recorded_without_claiming_delivery(): void
    {
        Http::fake(['https://msg.mram.com.bd/smsapi' => Http::response(['status' => 'success', 'message_id' => 'mram-test-123'], 200)]);

        $result = $this->gateway->send('+8801712345678', 'Username owner-test. Temporary password: one-time-value.');

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('mram-test-123', $result['provider_reference']);
        Http::assertSent(fn ($request) => $request->url() === 'https://msg.mram.com.bd/smsapi'
            && $request->data()['api_key'] === 'test-key-only'
            && $request->data()['type'] === 'text'
            && $request->data()['contacts'] === '8801712345678'
            && $request->data()['senderid'] === 'TEST-SENDER'
            && str_contains($request->data()['msg'], 'Username owner-test.')
            && $request->data()['label'] === 'transactional');
    }

    public function test_trusted_url_with_trailing_slash_is_accepted(): void
    {
        Http::fake(['https://msg.mram.com.bd/smsapi' => Http::response('OK', 200)]);
        config(['services.mram_sms.base_url' => 'https://msg.mram.com.bd/']);

        $this->assertSame('accepted', $this->gateway->send('01712345678', 'message')['status']);
        Http::assertSentCount(1);
    }

    public function test_test_mode_selects_only_test_credentials(): void
    {
        Http::fake(['https://msg.mram.com.bd/smsapi' => Http::response('OK', 200)]);

        $this->gateway->send('01712345678', 'message');

        Http::assertSent(fn ($request) => $request->data()['api_key'] === 'test-key-only'
            && $request->data()['senderid'] === 'TEST-SENDER');
    }

    public function test_response_diagnostics_are_disabled_by_default(): void
    {
        Log::spy();
        Http::fake(['https://msg.mram.com.bd/smsapi' => Http::response('OK', 200)]);

        $this->gateway->send('01712345678', 'message');

        Log::shouldNotHaveReceived('debug');
    }

    public function test_opt_in_response_diagnostics_record_only_safe_metadata(): void
    {
        Log::spy();
        Http::fake(['https://msg.mram.com.bd/smsapi' => Http::response(
            '{"status":"success","message_id":"provider-reference"}',
            200,
            ['Content-Type' => 'application/json'],
        )]);
        config(['services.mram_sms.response_diagnostics' => true]);

        $this->gateway->send('01712345678', 'message body must not be logged');

        Log::shouldHaveReceived('debug')->with('MRAM SMS response diagnostic', Mockery::on(function (array $context): bool {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $context['correlation_id']);
            $this->assertSame(200, $context['http_status']);
            $this->assertSame('application/json', $context['content_type']);
            $this->assertSame('json', $context['response_format']);
            $this->assertIsInt($context['body_length']);
            $this->assertNull($context['recognized_numeric_error_code']);
            $this->assertArrayNotHasKey('api_key', $context);
            $this->assertArrayNotHasKey('phone', $context);
            $this->assertArrayNotHasKey('message', $context);
            $this->assertArrayNotHasKey('raw_body', $context);

            return true;
        }));
    }

    public function test_live_mode_selects_only_live_credentials(): void
    {
        Http::fake(['https://msg.mram.com.bd/smsapi' => Http::response('OK', 200)]);
        config(['services.mram_sms.mode' => 'live']);

        $this->gateway->send('01712345678', 'message');

        Http::assertSent(fn ($request) => $request->data()['api_key'] === 'live-key-only'
            && $request->data()['senderid'] === 'LIVE-SENDER');
    }

    public function test_invalid_mode_rejects_safely_without_sending(): void
    {
        Http::fake();
        config(['services.mram_sms.mode' => 'sandbox']);

        $result = $this->gateway->send('01712345678', 'message');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('sms_provider_invalid_mode', $result['error_code']);
        Http::assertNothingSent();
    }

    public function test_missing_selected_mode_credentials_fail_safely_without_sending(): void
    {
        Http::fake();
        config(['services.mram_sms.credentials.test' => ['api_key' => null, 'sender_id' => null]]);

        $result = $this->gateway->send('01712345678', 'message');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('sms_provider_configuration_missing', $result['error_code']);
        Http::assertNothingSent();
    }

    public function test_url_validation_rejects_untrusted_components_without_sending(): void
    {
        Http::fake();
        $invalidUrls = [
            'https://example.com',
            'http://msg.mram.com.bd',
            'https://msg.mram.com.bd:443',
            'https://user@msg.mram.com.bd',
            'https://user:password@msg.mram.com.bd',
            'https://msg.mram.com.bd?redirect=evil',
            'https://msg.mram.com.bd#fragment',
            'https://msg.mram.com.bd/unexpected-path',
            'https://msg.mram.com.bd/smsapi',
        ];

        foreach ($invalidUrls as $url) {
            config(['services.mram_sms.base_url' => $url]);
            $result = $this->gateway->send('01712345678', 'message');
            $this->assertSame('unavailable', $result['status'], $url);
            $this->assertSame('sms_provider_host_not_trusted', $result['error_code'], $url);
        }

        Http::assertNothingSent();
    }

    public function test_redirect_response_is_not_followed_or_treated_as_acceptance(): void
    {
        Http::fake(['https://msg.mram.com.bd/smsapi' => Http::response('', 302, ['Location' => 'https://evil.example/collect'])]);

        $result = $this->gateway->send('01712345678', 'message');

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('sms_provider_http_unknown', $result['error_code']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://msg.mram.com.bd/smsapi');
    }

    public function test_auth_failure_and_rate_limit_are_rejected(): void
    {
        Http::fakeSequence()->pushStatus(401)->pushStatus(429);
        $this->assertSame('rejected', $this->gateway->send('01712345678', 'message')['status']);
        $this->assertSame('rejected', $this->gateway->send('01712345678', 'message')['status']);
    }

    public function test_invalid_phone_does_not_call_provider(): void
    {
        Http::fake();
        $result = $this->gateway->send('12345', 'message');
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('invalid_phone_number', $result['error_code']);
        Http::assertNothingSent();
    }

    public function test_timeout_network_failure_is_unknown_without_retry_signal(): void
    {
        Http::fake(fn () => throw new ConnectionException('simulated timeout'));
        $timeout = $this->gateway->send('01712345678', 'message');
        $this->assertSame('unknown', $timeout['status']);
    }

    public function test_malformed_response_is_unknown_without_claiming_acceptance(): void
    {
        Http::fake(['*' => Http::response('not-a-provider-response', 200)]);
        $malformed = $this->gateway->send('01712345678', 'message');
        $this->assertSame('unknown', $malformed['status']);
        $this->assertSame('sms_provider_response_unknown', $malformed['error_code']);
    }

    public function test_documented_numeric_error_codes_are_classified_without_treating_them_as_success(): void
    {
        $expected = [
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

        $responses = Http::fakeSequence();
        foreach (array_keys($expected) as $code) {
            $responses->push((string) $code, 200);
        }

        foreach ($expected as $code => $classification) {
            $result = $this->gateway->send('01712345678', 'message');
            $this->assertSame($classification['status'], $result['status'], $code);
            $this->assertSame($classification['error_code'], $result['error_code'], $code);
        }
    }

    public function test_numeric_response_requires_an_exact_documented_code_after_trimming(): void
    {
        Http::fakeSequence()
            ->push(" \n1002\t", 200)
            ->push('9999', 200)
            ->push('1002 accepted', 200);

        $trimmed = $this->gateway->send('01712345678', 'message');
        $undocumented = $this->gateway->send('01712345678', 'message');
        $embedded = $this->gateway->send('01712345678', 'message');

        $this->assertSame('rejected', $trimmed['status']);
        $this->assertSame('sms_provider_sender_id_not_found', $trimmed['error_code']);
        $this->assertSame('unknown', $undocumented['status']);
        $this->assertSame('sms_provider_response_unknown', $undocumented['error_code']);
        $this->assertSame('unknown', $embedded['status']);
        $this->assertSame('sms_provider_response_unknown', $embedded['error_code']);
    }

    public function test_missing_configuration_disabled_sms_and_untrusted_host_do_not_send(): void
    {
        Http::fake();
        config(['services.mram_sms.credentials.test.api_key' => null]);
        $this->assertSame('unavailable', $this->gateway->send('01712345678', 'message')['status']);
        config(['services.mram_sms.enabled' => false]);
        $this->assertSame('unavailable', $this->gateway->send('01712345678', 'message')['status']);
        config(['services.mram_sms.enabled' => true, 'services.mram_sms.base_url' => 'http://msg.mram.com.bd']);
        $this->assertSame('unavailable', $this->gateway->send('01712345678', 'message')['status']);
        Http::assertNothingSent();
    }
}
