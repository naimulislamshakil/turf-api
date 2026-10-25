<?php

namespace Tests\Feature;

use App\Services\MramSmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MramSmsGatewayTest extends TestCase
{
    private MramSmsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = app(MramSmsGateway::class);
        config(['services.mram_sms' => ['enabled' => true, 'base_url' => 'https://msg.mram.com.bd', 'api_key' => 'test-key-only', 'sender_id' => 'TEST-SENDER']]);
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
            && $request->data()['label'] === 'transactional');
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

    public function test_missing_configuration_disabled_sms_and_untrusted_host_do_not_send(): void
    {
        Http::fake();
        config(['services.mram_sms.api_key' => null]);
        $this->assertSame('unavailable', $this->gateway->send('01712345678', 'message')['status']);
        config(['services.mram_sms' => ['enabled' => false, 'base_url' => 'https://msg.mram.com.bd', 'api_key' => 'test-key-only', 'sender_id' => 'TEST-SENDER']]);
        $this->assertSame('unavailable', $this->gateway->send('01712345678', 'message')['status']);
        config(['services.mram_sms.enabled' => true, 'services.mram_sms.base_url' => 'http://msg.mram.com.bd']);
        $this->assertSame('unavailable', $this->gateway->send('01712345678', 'message')['status']);
        Http::assertNothingSent();
    }
}
