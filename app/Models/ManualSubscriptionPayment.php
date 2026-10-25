<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ManualSubscriptionPayment extends Model
{
    use HasUlids;

    public const METHOD_CASH = 'cash';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_BKASH = 'bkash';
    public const METHOD_NAGAD = 'nagad';
    public const STATUS_PENDING = 'pending_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public const METHODS = [self::METHOD_CASH, self::METHOD_BANK_TRANSFER, self::METHOD_BKASH, self::METHOD_NAGAD];

    protected $fillable = [
        'business_id', 'subscription_plan_version_id', 'billing_cycle', 'billing_periods', 'amount_paisa', 'currency', 'payment_method', 'transaction_reference', 'payment_date',
        'discount_type', 'discount_value_basis_points', 'original_subtotal_paisa', 'discount_amount_paisa', 'final_amount_paisa', 'status', 'recorded_by_platform_admin_id', 'verified_by_platform_admin_id', 'verification_at', 'payment_confirmed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['amount_paisa' => 'integer', 'billing_periods' => 'integer', 'discount_value_basis_points' => 'integer', 'original_subtotal_paisa' => 'integer', 'discount_amount_paisa' => 'integer', 'final_amount_paisa' => 'integer', 'payment_date' => 'datetime', 'verification_at' => 'datetime', 'payment_confirmed_at' => 'datetime'];
    }

    public function business() { return $this->belongsTo(Business::class); }
    public function planVersion() { return $this->belongsTo(SubscriptionPlanVersion::class, 'subscription_plan_version_id'); }
    public function subscription() { return $this->hasOne(BusinessSubscription::class); }
    public function verificationRecords() { return $this->hasMany(PaymentVerificationRecord::class); }
}
