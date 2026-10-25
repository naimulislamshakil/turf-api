<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class BusinessSubscription extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'business_id', 'subscription_plan_version_id', 'manual_subscription_payment_id', 'billing_cycle', 'billing_periods',
        'price_paisa', 'currency', 'status', 'starts_at', 'expires_at', 'activated_at',
    ];

    protected function casts(): array
    {
        return ['price_paisa' => 'integer', 'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime'];
    }

    public function business() { return $this->belongsTo(Business::class); }
    public function planVersion() { return $this->belongsTo(SubscriptionPlanVersion::class, 'subscription_plan_version_id'); }
    public function payment() { return $this->belongsTo(ManualSubscriptionPayment::class, 'manual_subscription_payment_id'); }
    public function lifecycleHistory() { return $this->hasMany(SubscriptionLifecycleHistory::class); }

    public function isCurrentlyActive(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now('UTC');
        return $this->status === self::STATUS_ACTIVE && $this->starts_at <= $now && $now < $this->expires_at;
    }
}
