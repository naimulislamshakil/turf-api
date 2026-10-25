<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\ManualSubscriptionPayment;
use App\Models\PaymentVerificationRecord;
use App\Models\SubscriptionLifecycleHistory;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanVersion;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessSubscriptionController extends Controller
{
    public function plans()
    {
        $plans = SubscriptionPlan::query()->where('status', SubscriptionPlan::STATUS_PUBLISHED)->with('versions')->orderBy('name')->get()->map(function (SubscriptionPlan $plan) {
            $version = $plan->versions->firstWhere('version', $plan->current_version);
            return $version ? [
                'plan_id' => $plan->id, 'plan_name' => $plan->name, 'plan_slug' => $plan->slug,
                'version_id' => $version->id, 'version' => $version->version,
                'monthly_price_paisa' => $version->monthly_price_paisa, 'yearly_price_paisa' => $version->yearly_price_paisa,
            ] : null;
        })->filter()->values();

        return response()->json(['data' => $plans]);
    }

    public function show(Business $business)
    {
        $business->load([
            'subscriptions.planVersion.plan',
            'subscriptions.payment',
            'subscriptions.lifecycleHistory',
        ]);
        $payments = ManualSubscriptionPayment::query()->where('business_id', $business->id)->with(['planVersion.plan', 'verificationRecords'])->latest()->get();
        $current = $business->subscriptions->sortByDesc('created_at')->first();

        return response()->json(['data' => [
            'business_id' => $business->id,
            'subscription' => $current ? $this->subscription($current) : null,
            'payments' => $payments->map(fn (ManualSubscriptionPayment $payment) => $this->payment($payment))->values(),
        ]]);
    }

    public function storePayment(Request $request, Business $business, AuditLogger $auditLogger)
    {
        $data = $request->validate([
            'subscription_plan_version_id' => ['required', 'string', 'exists:subscription_plan_versions,id'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'payment_method' => ['required', Rule::in(ManualSubscriptionPayment::METHODS)],
            'transaction_reference' => ['required', 'string', 'max:160', Rule::unique('manual_subscription_payments', 'transaction_reference')->where(fn ($query) => $query->where('payment_method', $request->input('payment_method')))],
            'payment_date' => ['required', 'date'],
        ]);

        $payment = DB::transaction(function () use ($data, $business, $request, $auditLogger): ManualSubscriptionPayment {
            $lockedBusiness = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if ($lockedBusiness->subscriptions()->where('status', BusinessSubscription::STATUS_ACTIVE)->exists()) {
                throw ValidationException::withMessages(['subscription' => 'This business already has an active subscription.']);
            }
            $version = $this->eligibleVersion($data['subscription_plan_version_id']);
            $amount = $data['billing_cycle'] === 'monthly' ? $version->monthly_price_paisa : $version->yearly_price_paisa;
            $payment = ManualSubscriptionPayment::create([
                'business_id' => $lockedBusiness->id,
                'subscription_plan_version_id' => $version->id,
                'billing_cycle' => $data['billing_cycle'],
                'amount_paisa' => $amount,
                'currency' => 'BDT',
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'],
                'payment_date' => $data['payment_date'],
                'status' => ManualSubscriptionPayment::STATUS_PENDING,
                'recorded_by_platform_admin_id' => $request->user()->getKey(),
            ]);
            $auditLogger->record('admin.subscription_payment.recorded', $request->user(), $request, ['payment_id' => $payment->id, 'business_id' => $lockedBusiness->id, 'amount_paisa' => $amount, 'billing_cycle' => $payment->billing_cycle, 'payment_method' => $payment->payment_method]);

            return $payment;
        });

        return response()->json(['data' => $this->payment($payment->load(['planVersion.plan', 'verificationRecords']))], 201);
    }

    public function completePayment(Request $request, Business $business, AuditLogger $auditLogger)
    {
        $data = $request->validate([
            'subscription_plan_version_id' => ['required', 'string', 'exists:subscription_plan_versions,id'],
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'billing_periods' => ['required', 'integer', 'min:1', 'max:120'],
            'discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'discount_value_basis_points' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['required', Rule::in(ManualSubscriptionPayment::METHODS)],
            'transaction_reference' => ['required', 'string', 'max:160', Rule::unique('manual_subscription_payments', 'transaction_reference')->where(fn ($query) => $query->where('payment_method', $request->input('payment_method')))],
            'payment_date' => ['required', 'date'],
            'payment_received_confirmation' => ['accepted'],
        ]);

        $result = DB::transaction(function () use ($data, $business, $request, $auditLogger): array {
            $lockedBusiness = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if ($lockedBusiness->subscriptions()->where('status', BusinessSubscription::STATUS_ACTIVE)->exists()) {
                throw ValidationException::withMessages(['subscription' => 'This business already has an active subscription.']);
            }
            $version = $this->eligibleVersion($data['subscription_plan_version_id']);
            $unitPrice = $data['billing_cycle'] === 'monthly' ? (int) $version->monthly_price_paisa : (int) $version->yearly_price_paisa;
            $subtotal = $unitPrice * (int) $data['billing_periods'];
            $discountType = $data['discount_type'] ?? null;
            $discountValue = (int) ($data['discount_value_basis_points'] ?? 0);
            if ($discountType === null && $discountValue !== 0) {
                throw ValidationException::withMessages(['discount_type' => 'Select a discount type.']);
            }
            if ($discountType === 'percentage' && $discountValue > 10000) {
                throw ValidationException::withMessages(['discount_value_basis_points' => 'Percentage discount cannot exceed 100%.']);
            }
            if ($discountType === 'fixed' && $discountValue > $subtotal) {
                throw ValidationException::withMessages(['discount_value_basis_points' => 'Fixed discount cannot exceed the subtotal.']);
            }
            $discountAmount = $discountType === 'percentage' ? intdiv($subtotal * $discountValue, 10000) : ($discountType === 'fixed' ? $discountValue : 0);
            $finalAmount = $subtotal - $discountAmount;
            $timezone = config('app.business_billing_timezone', 'Asia/Dhaka');
            $startsLocal = CarbonImmutable::now('UTC')->setTimezone($timezone);
            $expiresLocal = $data['billing_cycle'] === 'monthly'
                ? $startsLocal->addMonthsNoOverflow((int) $data['billing_periods'])
                : $startsLocal->addYearsNoOverflow((int) $data['billing_periods']);
            $now = $startsLocal->utc();
            $payment = ManualSubscriptionPayment::create([
                'business_id' => $lockedBusiness->id,
                'subscription_plan_version_id' => $version->id,
                'billing_cycle' => $data['billing_cycle'],
                'billing_periods' => $data['billing_periods'],
                'amount_paisa' => $finalAmount,
                'currency' => 'BDT',
                'payment_method' => $data['payment_method'],
                'transaction_reference' => $data['transaction_reference'],
                'payment_date' => $data['payment_date'],
                'discount_type' => $discountType,
                'discount_value_basis_points' => $discountValue,
                'original_subtotal_paisa' => $subtotal,
                'discount_amount_paisa' => $discountAmount,
                'final_amount_paisa' => $finalAmount,
                'status' => ManualSubscriptionPayment::STATUS_VERIFIED,
                'recorded_by_platform_admin_id' => $request->user()->getKey(),
                'verified_by_platform_admin_id' => $request->user()->getKey(),
                'verification_at' => $now,
                'payment_confirmed_at' => $now,
            ]);
            $subscription = BusinessSubscription::create([
                'business_id' => $lockedBusiness->id,
                'subscription_plan_version_id' => $version->id,
                'manual_subscription_payment_id' => $payment->id,
                'billing_cycle' => $data['billing_cycle'],
                'billing_periods' => $data['billing_periods'],
                'price_paisa' => $finalAmount,
                'currency' => 'BDT',
                'status' => BusinessSubscription::STATUS_ACTIVE,
                'starts_at' => $now,
                'expires_at' => $expiresLocal->utc(),
                'activated_at' => $now,
            ]);
            PaymentVerificationRecord::create(['manual_subscription_payment_id' => $payment->id, 'platform_admin_id' => $request->user()->getKey(), 'action' => ManualSubscriptionPayment::STATUS_VERIFIED, 'verified_at' => $now]);
            SubscriptionLifecycleHistory::create(['business_subscription_id' => $subscription->id, 'status' => BusinessSubscription::STATUS_ACTIVE, 'action' => 'activated', 'platform_admin_id' => $request->user()->getKey(), 'starts_at' => $subscription->starts_at, 'expires_at' => $subscription->expires_at, 'metadata' => ['payment_id' => $payment->id, 'plan_version_id' => $version->id, 'billing_periods' => $data['billing_periods'], 'discount_type' => $discountType, 'discount_value_basis_points' => $discountValue, 'discount_amount_paisa' => $discountAmount]]);
            $lockedBusiness->update(['status' => 'active']);
            $auditLogger->record('admin.subscription_purchase.completed', $request->user(), $request, ['payment_id' => $payment->id, 'subscription_id' => $subscription->id, 'business_id' => $lockedBusiness->id, 'original_subtotal_paisa' => $subtotal, 'discount_type' => $discountType, 'discount_value_basis_points' => $discountValue, 'discount_amount_paisa' => $discountAmount, 'final_amount_paisa' => $finalAmount]);
            return ['payment' => $payment->refresh()->load(['planVersion.plan', 'verificationRecords', 'subscription']), 'idempotent' => false];
        });

        return response()->json(['data' => $this->payment($result['payment']), 'idempotent' => $result['idempotent']], 201);
    }

    public function pendingPayments()
    {
        $payments = ManualSubscriptionPayment::query()->where('status', ManualSubscriptionPayment::STATUS_PENDING)->with(['business', 'planVersion.plan'])->latest()->get();
        return response()->json(['data' => $payments->map(fn (ManualSubscriptionPayment $payment) => $this->payment($payment))->values()]);
    }

    public function verify(Request $request, ManualSubscriptionPayment $payment, AuditLogger $auditLogger)
    {
        $result = DB::transaction(function () use ($request, $payment, $auditLogger): array {
            $lockedPayment = ManualSubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->with('planVersion.plan')->firstOrFail();
            if ($lockedPayment->status !== ManualSubscriptionPayment::STATUS_PENDING) {
                return ['payment' => $lockedPayment->load(['subscription', 'verificationRecords']), 'idempotent' => true];
            }
            $business = Business::query()->whereKey($lockedPayment->business_id)->lockForUpdate()->firstOrFail();
            if ($business->subscriptions()->where('status', BusinessSubscription::STATUS_ACTIVE)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['subscription' => 'This business already has an active subscription.']);
            }
            $version = $this->eligibleVersion($lockedPayment->subscription_plan_version_id);
            $expectedAmount = $lockedPayment->billing_cycle === 'monthly' ? $version->monthly_price_paisa : $version->yearly_price_paisa;
            if ((int) $lockedPayment->amount_paisa !== (int) $expectedAmount) {
                throw ValidationException::withMessages(['amount' => 'The recorded amount does not match the published plan price.']);
            }

            $timezone = config('app.business_billing_timezone', 'Asia/Dhaka');
            // Resolve the verification instant in UTC first, then convert that
            // instant into the configured business timezone before applying the
            // calendar boundary. This keeps the billing wall-clock time stable
            // even when Carbon's test clock carries a different timezone.
            $startsLocal = CarbonImmutable::now('UTC')->setTimezone($timezone);
            $expiresLocal = $lockedPayment->billing_cycle === 'monthly' ? $startsLocal->addMonthNoOverflow() : $startsLocal->addYearNoOverflow();
            $subscription = BusinessSubscription::create([
                'business_id' => $business->id,
                'subscription_plan_version_id' => $version->id,
                'manual_subscription_payment_id' => $lockedPayment->id,
                'billing_cycle' => $lockedPayment->billing_cycle,
                'price_paisa' => $expectedAmount,
                'currency' => $lockedPayment->currency,
                'status' => BusinessSubscription::STATUS_ACTIVE,
                'starts_at' => $startsLocal->utc(),
                'expires_at' => $expiresLocal->utc(),
                'activated_at' => $startsLocal->utc(),
            ]);
            $lockedPayment->update(['status' => ManualSubscriptionPayment::STATUS_VERIFIED, 'verified_by_platform_admin_id' => $request->user()->getKey(), 'verification_at' => $startsLocal->utc(), 'rejection_reason' => null]);
            PaymentVerificationRecord::create(['manual_subscription_payment_id' => $lockedPayment->id, 'platform_admin_id' => $request->user()->getKey(), 'action' => ManualSubscriptionPayment::STATUS_VERIFIED, 'verified_at' => $startsLocal->utc()]);
            SubscriptionLifecycleHistory::create(['business_subscription_id' => $subscription->id, 'status' => BusinessSubscription::STATUS_ACTIVE, 'action' => 'activated', 'platform_admin_id' => $request->user()->getKey(), 'starts_at' => $subscription->starts_at, 'expires_at' => $subscription->expires_at, 'metadata' => ['payment_id' => $lockedPayment->id, 'plan_version_id' => $version->id]]);
            $business->update(['status' => 'active']);
            $auditLogger->record('admin.subscription_payment.verified', $request->user(), $request, ['payment_id' => $lockedPayment->id, 'subscription_id' => $subscription->id, 'business_id' => $business->id]);

            return ['payment' => $lockedPayment->refresh()->load(['subscription', 'verificationRecords']), 'idempotent' => false];
        });

        return response()->json(['data' => $this->payment($result['payment']), 'idempotent' => $result['idempotent']]);
    }

    public function reject(Request $request, ManualSubscriptionPayment $payment, AuditLogger $auditLogger)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:1000']]);
        $result = DB::transaction(function () use ($request, $payment, $data, $auditLogger): array {
            $lockedPayment = ManualSubscriptionPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($lockedPayment->status !== ManualSubscriptionPayment::STATUS_PENDING) {
                return ['payment' => $lockedPayment->load('verificationRecords'), 'idempotent' => true];
            }
            $now = CarbonImmutable::now('UTC');
            $lockedPayment->update(['status' => ManualSubscriptionPayment::STATUS_REJECTED, 'verified_by_platform_admin_id' => $request->user()->getKey(), 'verification_at' => $now, 'rejection_reason' => $data['rejection_reason']]);
            PaymentVerificationRecord::create(['manual_subscription_payment_id' => $lockedPayment->id, 'platform_admin_id' => $request->user()->getKey(), 'action' => ManualSubscriptionPayment::STATUS_REJECTED, 'verified_at' => $now, 'rejection_reason' => $data['rejection_reason']]);
            $auditLogger->record('admin.subscription_payment.rejected', $request->user(), $request, ['payment_id' => $lockedPayment->id, 'business_id' => $lockedPayment->business_id]);
            return ['payment' => $lockedPayment->refresh()->load('verificationRecords'), 'idempotent' => false];
        });

        return response()->json(['data' => $this->payment($result['payment']), 'idempotent' => $result['idempotent']]);
    }

    private function eligibleVersion(string $id): SubscriptionPlanVersion
    {
        $version = SubscriptionPlanVersion::query()->with('plan')->whereKey($id)->firstOrFail();
        if ($version->plan?->status !== SubscriptionPlan::STATUS_PUBLISHED || (int) $version->plan->current_version !== (int) $version->version || ! $version->published_at) {
            throw ValidationException::withMessages(['subscription_plan_version_id' => 'Select the current published plan version.']);
        }
        return $version;
    }

    private function payment(ManualSubscriptionPayment $payment): array
    {
        return [
            'id' => $payment->id, 'business_id' => $payment->business_id, 'status' => $payment->status,
            'amount_paisa' => $payment->amount_paisa, 'currency' => $payment->currency,
            'payment_method' => $payment->payment_method, 'transaction_reference' => $payment->transaction_reference,
            'payment_date' => $payment->payment_date?->toISOString(), 'billing_cycle' => $payment->billing_cycle, 'billing_periods' => $payment->billing_periods,
            'discount_type' => $payment->discount_type, 'discount_value_basis_points' => $payment->discount_value_basis_points, 'original_subtotal_paisa' => $payment->original_subtotal_paisa, 'discount_amount_paisa' => $payment->discount_amount_paisa, 'final_amount_paisa' => $payment->final_amount_paisa,
            'plan' => $payment->planVersion ? ['version_id' => $payment->planVersion->id, 'version' => $payment->planVersion->version, 'name' => $payment->planVersion->plan?->name] : null,
            'rejection_reason' => $payment->rejection_reason,
            'verification_at' => $payment->verification_at?->toISOString(),
            'subscription' => $payment->relationLoaded('subscription') && $payment->subscription ? $this->subscription($payment->subscription) : null,
            'verification_records' => $payment->relationLoaded('verificationRecords') ? $payment->verificationRecords->map(fn (PaymentVerificationRecord $record) => ['id' => $record->id, 'action' => $record->action, 'verified_at' => $record->verified_at?->toISOString(), 'rejection_reason' => $record->rejection_reason])->values() : [],
        ];
    }

    private function subscription(BusinessSubscription $subscription): array
    {
        $isAccessible = $subscription->isCurrentlyActive();
        return [
            'id' => $subscription->id, 'status' => $subscription->status, 'access_status' => $isAccessible ? 'active' : 'expired',
            'billing_cycle' => $subscription->billing_cycle, 'billing_periods' => $subscription->billing_periods, 'price_paisa' => $subscription->price_paisa, 'currency' => $subscription->currency,
            'starts_at' => $subscription->starts_at?->toISOString(), 'expires_at' => $subscription->expires_at?->toISOString(), 'activated_at' => $subscription->activated_at?->toISOString(),
            'plan' => $subscription->planVersion ? ['version_id' => $subscription->planVersion->id, 'version' => $subscription->planVersion->version, 'name' => $subscription->planVersion->plan?->name] : null,
            'payment_id' => $subscription->manual_subscription_payment_id,
        ];
    }
}
