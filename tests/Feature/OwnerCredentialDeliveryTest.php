<?php

namespace Tests\Feature;

use App\Jobs\DeliverOwnerCredentials;
use App\Models\Business;
use App\Models\CredentialDelivery;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\NullSmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Mail\MailManager;
use Tests\TestCase;

class OwnerCredentialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_mailer_and_unconfigured_sms_are_marked_unavailable_without_claiming_delivery(): void
    {
        config(['mail.default' => 'log']);
        $owner = User::factory()->create(['phone' => '+8801912345678']);
        $business = Business::create(['name' => 'Delivery Test', 'slug' => 'delivery-test', 'phone' => '+8801712345678', 'email' => null, 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => Business::STATUS_PENDING]);
        $payload = Crypt::encryptString(json_encode(['owner_name' => $owner->name, 'business_name' => $business->name, 'username' => $owner->username, 'email' => $owner->email, 'phone' => $owner->phone, 'temporary_password' => 'never-in-storage', 'expires_at' => now()->addDay()->toISOString()], JSON_THROW_ON_ERROR));

        foreach ([CredentialDelivery::CHANNEL_EMAIL, CredentialDelivery::CHANNEL_SMS] as $channel) {
            $delivery = CredentialDelivery::create(['user_id' => $owner->id, 'business_id' => $business->id, 'channel' => $channel, 'destination_hash' => hash('sha256', $channel === 'email' ? $owner->email : $owner->phone)]);
            (new DeliverOwnerCredentials($delivery->id, $payload))->handle(app(MailManager::class), new NullSmsGateway());
        }

        $this->assertSame(2, CredentialDelivery::where('status', CredentialDelivery::STATUS_UNAVAILABLE)->count());
        $this->assertNotSame('never-in-storage', $payload);
        $this->assertDatabaseMissing('credential_deliveries', ['last_error_code' => 'sent']);
    }

    public function test_failed_delivery_can_be_retried_without_generating_new_credentials(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['phone' => '+8801912345678', 'must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()]);
        $business = Business::create(['name' => 'Retry Test', 'slug' => 'retry-test', 'phone' => '+8801712345678', 'email' => null, 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => Business::STATUS_PENDING]);
        $payload = Crypt::encryptString(json_encode(['owner_name' => $owner->name, 'business_name' => $business->name, 'username' => $owner->username, 'email' => $owner->email, 'phone' => $owner->phone, 'temporary_password' => 'one-time-secret', 'expires_at' => now()->addDay()->toISOString()], JSON_THROW_ON_ERROR));
        $delivery = CredentialDelivery::create(['user_id' => $owner->id, 'business_id' => $business->id, 'channel' => CredentialDelivery::CHANNEL_SMS, 'destination_hash' => hash('sha256', $owner->phone), 'encrypted_payload' => $payload, 'status' => CredentialDelivery::STATUS_UNAVAILABLE]);
        $admin = PlatformAdmin::create(['name' => 'Retry Admin', 'email' => 'retry-admin@example.com', 'password' => Hash::make('password'), 'is_active' => true]);

        $this->actingAs($admin, 'platform_admin')->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/admin/credential-deliveries/'.$delivery->id.'/retry')->assertOk()->assertJsonPath('data.status', CredentialDelivery::STATUS_QUEUED);
        Queue::assertPushed(DeliverOwnerCredentials::class, fn (DeliverOwnerCredentials $job) => $job->deliveryId === $delivery->id && $job->encryptedPayload === $payload);
        $this->assertDatabaseHas('credential_deliveries', ['id' => $delivery->id, 'status' => CredentialDelivery::STATUS_QUEUED]);
    }
}
