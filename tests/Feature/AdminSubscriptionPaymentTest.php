<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\ManualSubscriptionPayment;
use App\Models\PlatformAdmin;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanVersion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminSubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_recording_payment_uses_backend_price_and_stays_pending(): void
    {
        [$business, $version] = $this->businessAndVersion();
        $response = $this->adminSession()->postJson('/api/v1/admin/businesses/'.$business->id.'/subscription/payments', [
            'subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'payment_method' => 'bkash',
            'transaction_reference' => 'BKASH-001', 'payment_date' => '2026-01-31 10:00:00', 'amount_paisa' => 1,
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'pending_verification')->assertJsonPath('data.amount_paisa', 125000);
        $this->assertDatabaseHas('manual_subscription_payments', ['transaction_reference' => 'BKASH-001', 'status' => 'pending_verification', 'amount_paisa' => 125000]);
        $this->assertSame('pending', $business->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.subscription_payment.recorded']);
    }

    public function test_verification_activates_once_with_calendar_month_expiry_and_is_idempotent(): void
    {
        [$business, $version] = $this->businessAndVersion();
        $this->freezeLocal('2026-01-31 15:45:12');
        $payment = $this->recordPayment($business, $version, 'monthly', 'BANK-001');

        $first = $this->adminSession()->postJson('/api/v1/admin/subscription-payments/'.$payment->id.'/verify')->assertOk();
        $first->assertJsonPath('idempotent', false)->assertJsonPath('data.status', 'verified');
        $subscription = BusinessSubscription::firstOrFail();
        $startsLocal = CarbonImmutable::parse($subscription->starts_at)->setTimezone('Asia/Dhaka');
        $expiresLocal = CarbonImmutable::parse($subscription->expires_at)->setTimezone('Asia/Dhaka');
        $this->assertSame('2026-01-31 15:45:12', $startsLocal->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-28 15:45:12', $expiresLocal->format('Y-m-d H:i:s'));
        $this->assertSame('active', $business->fresh()->status);

        $second = $this->adminSession()->postJson('/api/v1/admin/subscription-payments/'.$payment->id.'/verify')->assertOk();
        $second->assertJsonPath('idempotent', true);
        $this->assertCount(1, BusinessSubscription::all());
    }

    public function test_yearly_leap_day_uses_calendar_year_and_expiry_is_exclusive(): void
    {
        [$business, $version] = $this->businessAndVersion();
        $this->freezeLocal('2028-02-29 23:10:00');
        $payment = $this->recordPayment($business, $version, 'yearly', 'CASH-001');
        $this->adminSession()->postJson('/api/v1/admin/subscription-payments/'.$payment->id.'/verify')->assertOk();
        $subscription = BusinessSubscription::firstOrFail();
        $this->assertSame('2029-02-28 23:10:00', CarbonImmutable::parse($subscription->expires_at)->setTimezone('Asia/Dhaka')->format('Y-m-d H:i:s'));
        $this->assertTrue($subscription->isCurrentlyActive(CarbonImmutable::parse($subscription->expires_at)->subSecond()));
        $this->assertFalse($subscription->isCurrentlyActive(CarbonImmutable::parse($subscription->expires_at)));
    }

    public function test_rejection_does_not_activate_and_duplicate_reference_is_rejected(): void
    {
        [$business, $version] = $this->businessAndVersion();
        $payment = $this->recordPayment($business, $version, 'monthly', 'NAGAD-001');
        $this->adminSession()->postJson('/api/v1/admin/subscription-payments/'.$payment->id.'/reject', ['rejection_reason' => 'Reference could not be verified.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, BusinessSubscription::count());
        $this->assertSame('pending', $business->fresh()->status);
        $this->adminSession()->postJson('/api/v1/admin/businesses/'.$business->id.'/subscription/payments', ['subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'payment_method' => 'bank_transfer', 'transaction_reference' => 'NAGAD-001', 'payment_date' => now()->toDateTimeString()])->assertUnprocessable()->assertJsonValidationErrors('transaction_reference');
    }

    public function test_non_admin_cannot_record_or_verify_payments(): void
    {
        [$business, $version] = $this->businessAndVersion();
        $this->actingAs(\App\Models\User::factory()->create(), 'web')->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/admin/businesses/'.$business->id.'/subscription/payments', ['subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'payment_method' => 'cash', 'transaction_reference' => 'NOPE', 'payment_date' => now()->toDateTimeString()])->assertUnauthorized();
    }

    public function test_complete_payment_applies_discount_and_activates_multi_period_subscription(): void
    {
        [$business, $version] = $this->businessAndVersion();
        $response = $this->adminSession()->postJson('/api/v1/admin/businesses/'.$business->id.'/subscription/complete-payment', [
            'subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'billing_periods' => 3,
            'discount_type' => 'percentage', 'discount_value_basis_points' => 1000,
            'payment_method' => 'bank_transfer', 'transaction_reference' => 'COMPLETE-001', 'payment_date' => '2026-01-31', 'payment_received_confirmation' => true,
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.original_subtotal_paisa', 375000)->assertJsonPath('data.discount_amount_paisa', 37500)->assertJsonPath('data.final_amount_paisa', 337500);
        $this->assertDatabaseHas('business_subscriptions', ['business_id' => $business->id, 'billing_periods' => 3, 'price_paisa' => 337500, 'status' => 'active']);
        $this->assertSame('active', $business->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.subscription_purchase.completed']);
    }

    public function test_complete_payment_rejects_excessive_discount_and_missing_confirmation(): void
    {
        [$business, $version] = $this->businessAndVersion();
        $this->adminSession()->postJson('/api/v1/admin/businesses/'.$business->id.'/subscription/complete-payment', [
            'subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'billing_periods' => 1,
            'discount_type' => 'fixed', 'discount_value_basis_points' => 125001,
            'payment_method' => 'cash', 'transaction_reference' => 'COMPLETE-002', 'payment_date' => '2026-01-31', 'payment_received_confirmation' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('discount_value_basis_points');
        $this->adminSession()->postJson('/api/v1/admin/businesses/'.$business->id.'/subscription/complete-payment', [
            'subscription_plan_version_id' => $version->id, 'billing_cycle' => 'monthly', 'billing_periods' => 1,
            'discount_type' => null, 'discount_value_basis_points' => 0,
            'payment_method' => 'cash', 'transaction_reference' => 'COMPLETE-003', 'payment_date' => '2026-01-31', 'payment_received_confirmation' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_received_confirmation');
        $this->assertSame(0, BusinessSubscription::count());
    }

    private function businessAndVersion(): array
    {
        $business = Business::create(['name' => 'Subscription Business', 'slug' => 'subscription-business-'.strtolower(bin2hex(random_bytes(2))), 'phone' => '+8801712345678', 'address' => 'Address', 'district_city' => 'Dhaka', 'status' => 'pending']);
        $plan = SubscriptionPlan::create(['name' => 'Growth', 'slug' => 'growth-'.strtolower(bin2hex(random_bytes(2))), 'status' => SubscriptionPlan::STATUS_PUBLISHED, 'monthly_price_paisa' => 125000, 'yearly_price_paisa' => 1250000, 'module_entitlements' => ['turf' => true], 'current_version' => 1, 'published_at' => now()]);
        $version = SubscriptionPlanVersion::create(['subscription_plan_id' => $plan->id, 'version' => 1, 'name' => $plan->name, 'slug' => $plan->slug, 'monthly_price_paisa' => 125000, 'yearly_price_paisa' => 1250000, 'module_entitlements' => ['turf' => true], 'published_at' => now()]);
        return [$business, $version];
    }

    private function recordPayment(Business $business, SubscriptionPlanVersion $version, string $cycle, string $reference): ManualSubscriptionPayment
    {
        $response = $this->adminSession()->postJson('/api/v1/admin/businesses/'.$business->id.'/subscription/payments', ['subscription_plan_version_id' => $version->id, 'billing_cycle' => $cycle, 'payment_method' => $cycle === 'monthly' ? 'bank_transfer' : 'cash', 'transaction_reference' => $reference, 'payment_date' => now()->toDateTimeString()])->assertCreated();
        return ManualSubscriptionPayment::findOrFail($response->json('data.id'));
    }

    private function freezeLocal(string $date): void { CarbonImmutable::setTestNow(CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date, 'UTC')); }

    private function adminSession() { return $this->actingAs($this->admin(), 'platform_admin')->withHeader('Origin', 'http://localhost:3000'); }
    private function admin(): PlatformAdmin { return PlatformAdmin::firstOrCreate(['email' => 'subscription-admin@example.com'], ['name' => 'Subscription Admin', 'password' => Hash::make('correct-password'), 'is_active' => true]); }
}
