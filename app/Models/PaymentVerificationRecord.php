<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PaymentVerificationRecord extends Model
{
    use HasUlids;

    protected $fillable = ['manual_subscription_payment_id', 'platform_admin_id', 'action', 'verified_at', 'rejection_reason'];

    protected function casts(): array { return ['verified_at' => 'datetime']; }

    public function payment() { return $this->belongsTo(ManualSubscriptionPayment::class, 'manual_subscription_payment_id'); }
}
