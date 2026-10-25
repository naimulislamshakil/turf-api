<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionLifecycleHistory extends Model
{
    use HasUlids;

    protected $table = 'subscription_lifecycle_history';
    protected $fillable = ['business_subscription_id', 'status', 'action', 'platform_admin_id', 'starts_at', 'expires_at', 'metadata'];

    protected function casts(): array { return ['starts_at' => 'datetime', 'expires_at' => 'datetime', 'metadata' => 'array']; }

    public function subscription() { return $this->belongsTo(BusinessSubscription::class, 'business_subscription_id'); }
}
