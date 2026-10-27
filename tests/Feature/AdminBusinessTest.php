<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\ManualSubscriptionPayment;
use App\Models\PlatformAdmin;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminBusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_an_empty_successful_business_list(): void
    {
        $this->adminSession()->getJson('/api/v1/admin/businesses')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_admin_can_create_a_pending_business_for_an_existing_user(): void
    {
        Queue::fake();
        $plan = $this->publishedPlan();

        $response = $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload($plan));

        $response->assertCreated()->assertJsonPath('data.status', 'pending');
        $owner = User::where('email', 'owner@green-field.test')->firstOrFail();
        $this->assertTrue($owner->must_change_password);
        $this->assertNotNull($owner->temporary_password_expires_at);
        $this->assertDatabaseHas('businesses', ['slug' => 'green-field', 'status' => 'pending', 'onboarding_plan_id' => $plan->id]);
        $this->assertDatabaseHas('business_owners', ['business_id' => $response->json('data.id'), 'user_id' => $owner->id]);
        $this->assertDatabaseHas('credential_deliveries', ['business_id' => $response->json('data.id'), 'channel' => 'email', 'status' => 'queued']);
        $this->assertDatabaseHas('credential_deliveries', ['business_id' => $response->json('data.id'), 'channel' => 'sms', 'status' => 'queued']);
        Queue::assertPushed(\App\Jobs\DeliverOwnerCredentials::class, 2);
        $this->assertStringNotContainsString('temporary_password', json_encode($response->json()));
        $this->adminSession()->getJson('/api/v1/admin/businesses')->assertOk()->assertJsonPath('data.0.slug', 'green-field');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.business.created']);
    }

    public function test_new_owner_receives_an_eight_digit_hashed_temporary_password(): void
    {
        Queue::fake();

        $response = $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload())->assertCreated();
        $owner = User::where('email', 'owner@green-field.test')->firstOrFail();
        $delivery = \App\Models\CredentialDelivery::query()
            ->where('business_id', $response->json('data.id'))
            ->where('channel', 'sms')
            ->firstOrFail();
        $credentials = json_decode(Crypt::decryptString($delivery->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);

        $this->assertMatchesRegularExpression('/^[0-9]{8}$/', $credentials['temporary_password']);
        $this->assertTrue(Hash::check($credentials['temporary_password'], $owner->password));
        $this->assertNotSame($credentials['temporary_password'], $owner->password);
        $this->assertTrue($owner->must_change_password);
        $this->assertTrue($owner->temporary_password_expires_at->between(now()->addHours(23), now()->addHours(25)));
        $this->assertStringNotContainsString($credentials['temporary_password'], json_encode($response->json()));
    }

    public function test_each_new_business_gets_a_new_owner_identity(): void
    {
        $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload());
        $second = $this->payload();
        $second['name'] = 'Blue Field';
        $second['slug'] = 'blue-field';
        $second['owner_email'] = 'owner@blue-field.test';
        $second['owner_phone'] = '+8801712345679';
        $second['owner_username'] = 'blue-owner';

        $this->adminSession()->postJson('/api/v1/admin/businesses', $second)->assertCreated();
        $this->assertCount(2, User::whereIn('email', ['owner@green-field.test', 'owner@blue-field.test'])->get());
    }

    public function test_duplicate_slugs_and_non_admin_access_are_rejected(): void
    {
        $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload())->assertCreated();
        $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['slug', 'owner_email', 'owner_phone']);
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/v1/admin/businesses')->assertUnauthorized();
    }

    public function test_owner_credentials_are_never_returned(): void
    {
        $response = $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload());
        $response->assertCreated()->assertJsonMissing(['password'])->assertJsonMissing(['temporary_password']);
    }

    public function test_admin_can_update_editable_fields_without_changing_owner(): void
    {
        $created = $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload())->assertCreated();
        $businessId = $created->json('data.id');
        $ownerId = $created->json('data.owner.id');
        $this->adminSession()->patchJson('/api/v1/admin/businesses/'.$businessId, ['name' => 'Updated Field', 'slug' => 'updated-field', 'phone' => '+8801999999999', 'email' => null, 'address' => 'New address', 'district_city' => 'Chattogram'])->assertOk()->assertJsonPath('data.name', 'Updated Field');
        $this->assertDatabaseHas('businesses', ['id' => $businessId, 'slug' => 'updated-field']);
        $this->assertDatabaseHas('business_owners', ['business_id' => $businessId, 'user_id' => $ownerId]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.business.updated']);
    }

    public function test_business_is_soft_deleted_and_history_is_preserved(): void
    {
        $created = $this->adminSession()->postJson('/api/v1/admin/businesses', $this->payload())->assertCreated();
        $businessId = $created->json('data.id');
        $this->adminSession()->deleteJson('/api/v1/admin/businesses/'.$businessId, ['deletion_reason' => 'Business closed'])->assertOk();
        $this->assertSoftDeleted('businesses', ['id' => $businessId]);
        $this->assertDatabaseHas('business_owners', ['business_id' => $businessId]);
        $this->adminSession()->getJson('/api/v1/admin/businesses')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.business.deleted']);
    }

    public function test_deletion_is_blocked_for_active_subscription_and_pending_payment(): void
    {
        $activeBusiness = Business::create(array_merge($this->businessAttributes('active-business'), ['status' => 'active']));
        [$plan, $version] = $this->planAndVersion();
        $activePayment = ManualSubscriptionPayment::create(['business_id' => $activeBusiness->id, 'subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'amount_paisa' => 100000, 'currency' => 'BDT', 'payment_method' => 'cash', 'transaction_reference' => 'ACTIVE-001', 'payment_date' => now(), 'status' => ManualSubscriptionPayment::STATUS_VERIFIED, 'recorded_by_platform_admin_id' => $this->admin()->id]);
        BusinessSubscription::create(['business_id' => $activeBusiness->id, 'subscription_plan_version_id' => $version->id, 'manual_subscription_payment_id' => $activePayment->id, 'billing_cycle' => 'monthly', 'price_paisa' => 100000, 'currency' => 'BDT', 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'activated_at' => now()->subDay()]);
        $this->adminSession()->deleteJson('/api/v1/admin/businesses/'.$activeBusiness->id, ['deletion_reason' => 'Attempt'])->assertUnprocessable()->assertJsonValidationErrors('business');

        $pendingBusiness = Business::create($this->businessAttributes('pending-business'));
        ManualSubscriptionPayment::create(['business_id' => $pendingBusiness->id, 'subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'amount_paisa' => 100000, 'currency' => 'BDT', 'payment_method' => 'bank_transfer', 'transaction_reference' => 'PENDING-001', 'payment_date' => now(), 'status' => ManualSubscriptionPayment::STATUS_PENDING, 'recorded_by_platform_admin_id' => $this->admin()->id]);
        $this->adminSession()->deleteJson('/api/v1/admin/businesses/'.$pendingBusiness->id, ['deletion_reason' => 'Attempt'])->assertUnprocessable()->assertJsonValidationErrors('business');
        $this->assertDatabaseHas('businesses', ['id' => $activeBusiness->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('businesses', ['id' => $pendingBusiness->id, 'deleted_at' => null]);
    }

    public function test_non_admin_cannot_update_or_delete_business(): void
    {
        $business = Business::create($this->businessAttributes('secure-business'));
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->patchJson('/api/v1/admin/businesses/'.$business->id, [])->assertUnauthorized();
        $this->actingAs($user, 'web')->deleteJson('/api/v1/admin/businesses/'.$business->id, ['deletion_reason' => 'No'])->assertUnauthorized();
    }

    public function test_logo_and_normalized_username_are_persisted_safely(): void
    {
        Queue::fake();
        Storage::fake('public');
        $payload = $this->payload();
        $payload['owner_username'] = 'Green.Owner';
        $payload['logo'] = UploadedFile::fake()->image('logo.png', 100, 100);
        $response = $this->adminSession()->post('/api/v1/admin/businesses', $payload);
        $response->assertCreated();
        $this->assertDatabaseHas('users', ['username' => 'green.owner']);
        $business = Business::findOrFail($response->json('data.id'));
        $this->assertNotNull($business->logo_path);
        Storage::disk('public')->assertExists($business->logo_path);
    }

    private function adminSession()
    {
        return $this->actingAs($this->admin(), 'platform_admin')->withHeader('Origin', 'http://localhost:3000');
    }

    private function admin(): PlatformAdmin
    {
        return PlatformAdmin::firstOrCreate(['email' => 'businesses-admin@example.com'], ['name' => 'Businesses Admin', 'password' => Hash::make('correct-password'), 'is_active' => true]);
    }

    private function payload(?SubscriptionPlan $plan = null): array
    {
        return ['name' => 'Green Field', 'slug' => 'green-field', 'phone' => '+8801712345678', 'email' => 'hello@green-field.test', 'address' => '12 Stadium Road', 'district_city' => 'Dhaka', 'owner_name' => 'Green Owner', 'owner_username' => 'green-owner', 'owner_email' => 'owner@green-field.test', 'owner_phone' => '+8801812345678', 'onboarding_plan_id' => $plan?->id];
    }

    private function publishedPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Published Starter', 'slug' => 'published-starter', 'description' => null,
            'status' => SubscriptionPlan::STATUS_PUBLISHED, 'monthly_price_paisa' => 100000,
            'yearly_price_paisa' => 1000000, 'module_entitlements' => ['turf' => true], 'current_version' => 1,
            'published_at' => now(),
        ]);
    }

    private function businessAttributes(string $slug): array
    {
        return ['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'phone' => '+8801712345678', 'email' => $slug.'@example.test', 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => 'pending'];
    }

    private function planAndVersion(): array
    {
        $plan = SubscriptionPlan::create(['name' => 'Delete Test Plan', 'slug' => 'delete-test-plan', 'status' => SubscriptionPlan::STATUS_PUBLISHED, 'monthly_price_paisa' => 100000, 'yearly_price_paisa' => 1000000, 'module_entitlements' => ['turf' => true], 'current_version' => 1, 'published_at' => now()]);
        $version = SubscriptionPlanVersion::create(['subscription_plan_id' => $plan->id, 'version' => 1, 'name' => $plan->name, 'slug' => $plan->slug, 'monthly_price_paisa' => 100000, 'yearly_price_paisa' => 1000000, 'module_entitlements' => ['turf' => true], 'published_at' => now()]);
        return [$plan, $version];
    }
}
