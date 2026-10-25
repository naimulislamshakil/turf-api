<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'name', 'slug', 'phone', 'email', 'logo_path', 'address', 'district_city', 'status', 'onboarding_plan_id',
    ];

    public function owners()
    {
        return $this->belongsToMany(User::class, 'business_owners')->withTimestamps();
    }

    public function onboardingPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'onboarding_plan_id');
    }

    public function credentialDeliveries()
    {
        return $this->hasMany(CredentialDelivery::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(BusinessSubscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(BusinessSubscription::class)->where('status', BusinessSubscription::STATUS_ACTIVE)->latestOfMany();
    }
}
