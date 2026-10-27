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

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::create(['name' => 'Credential Admin', 'email' => fake()->unique()->safeEmail(), 'password' => Hash::make('password'), 'is_active' => true]);
    }

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

    public function test_expired_delivery_retry_remains_blocked(): void
    {
        $owner = User::factory()->create(['phone' => '+8801912345678', 'must_change_password' => true, 'temporary_password_expires_at' => now()->subMinute()]);
        $business = Business::create(['name' => 'Expired Retry Test', 'slug' => 'expired-retry-test', 'phone' => '+8801712345678', 'email' => null, 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => Business::STATUS_PENDING]);
        $business->owners()->attach($owner->id, ['id' => (string) \Illuminate\Support\Str::ulid()]);
        $delivery = CredentialDelivery::create(['user_id' => $owner->id, 'business_id' => $business->id, 'channel' => CredentialDelivery::CHANNEL_SMS, 'destination_hash' => hash('sha256', $owner->phone), 'encrypted_payload' => 'redacted-test-payload', 'status' => CredentialDelivery::STATUS_UNKNOWN]);

        $this->actingAs($this->admin(), 'platform_admin')
            ->postJson('/api/v1/admin/credential-deliveries/'.$delivery->id.'/retry')
            ->assertUnprocessable()
            ->assertJsonPath('errors.delivery.0', 'This temporary credential is no longer eligible for delivery retry.');
    }

    public function test_platform_admin_can_reissue_and_supersede_old_credentials_without_returning_password(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['password' => Hash::make('old-temporary-password'), 'phone' => '+8801912345678', 'must_change_password' => true, 'temporary_password_expires_at' => now()->subDay()]);
        $business = Business::create(['name' => 'Reissue Test', 'slug' => 'reissue-test', 'phone' => '+8801712345678', 'email' => 'business@example.com', 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => Business::STATUS_PENDING]);
        $business->owners()->attach($owner->id, ['id' => (string) \Illuminate\Support\Str::ulid()]);
        $oldPayload = Crypt::encryptString(json_encode(['temporary_password' => 'old-temporary-password'], JSON_THROW_ON_ERROR));
        $oldDelivery = CredentialDelivery::create(['user_id' => $owner->id, 'business_id' => $business->id, 'channel' => CredentialDelivery::CHANNEL_SMS, 'destination_hash' => hash('sha256', $owner->phone), 'encrypted_payload' => $oldPayload, 'status' => CredentialDelivery::STATUS_UNKNOWN]);

        $response = $this->actingAs($this->admin(), 'platform_admin')
            ->postJson('/api/v1/admin/businesses/'.$business->id.'/credential-reissue', ['confirmed' => true, 'reason' => 'Expired credential requires replacement.'])
            ->assertOk()
            ->assertJsonMissingPath('data.temporary_password')
            ->assertJsonMissing(['old-temporary-password'])
            ->assertJsonStructure(['data' => ['owner_id', 'credential_expires_at', 'credential_deliveries']]);

        $owner->refresh();
        $this->assertFalse(Hash::check('old-temporary-password', $owner->password));
        $this->assertTrue($owner->must_change_password);
        $this->assertNotNull($owner->temporary_password_expires_at);
        $this->assertTrue($owner->temporary_password_expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertDatabaseHas('credential_deliveries', ['id' => $oldDelivery->id, 'status' => CredentialDelivery::STATUS_SUPERSEDED, 'encrypted_payload' => null]);
        $this->assertSame(2, CredentialDelivery::where('business_id', $business->id)->where('status', CredentialDelivery::STATUS_QUEUED)->count());
        $freshDelivery = CredentialDelivery::where('business_id', $business->id)->where('status', CredentialDelivery::STATUS_QUEUED)->firstOrFail();
        $freshCredentials = json_decode(Crypt::decryptString($freshDelivery->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', $freshCredentials['temporary_password']);
        $this->assertNotSame('old-temporary-password', $freshCredentials['temporary_password']);
        $this->assertTrue(Hash::check($freshCredentials['temporary_password'], $owner->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'owner.credential.reissued']);
        Queue::assertPushed(DeliverOwnerCredentials::class, 2);
        $response->assertJsonMissing(['password', 'temporary_password', 'encrypted_payload']);
    }

    public function test_completed_owner_cannot_be_reissued_and_unauthorized_users_are_rejected(): void
    {
        $owner = User::factory()->create(['must_change_password' => false, 'temporary_password_expires_at' => null]);
        $business = Business::create(['name' => 'Completed Owner Test', 'slug' => 'completed-owner-test', 'phone' => '+8801712345678', 'email' => null, 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => Business::STATUS_PENDING]);
        $business->owners()->attach($owner->id, ['id' => (string) \Illuminate\Support\Str::ulid()]);

        $this->actingAs($this->admin(), 'platform_admin')
            ->postJson('/api/v1/admin/businesses/'.$business->id.'/credential-reissue', ['confirmed' => true, 'reason' => 'Support requested a replacement.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('owner');

    }

    public function test_unauthorized_users_cannot_reissue_credentials(): void
    {
        $owner = User::factory()->create(['must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()]);
        $business = Business::create(['name' => 'Unauthorized Reissue Test', 'slug' => 'unauthorized-reissue-test', 'phone' => '+8801712345678', 'email' => null, 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => Business::STATUS_PENDING]);
        $business->owners()->attach($owner->id, ['id' => (string) \Illuminate\Support\Str::ulid()]);

        $this->postJson('/api/v1/admin/businesses/'.$business->id.'/credential-reissue', ['confirmed' => true, 'reason' => 'Not authorized.'])
            ->assertUnauthorized();
    }
}
