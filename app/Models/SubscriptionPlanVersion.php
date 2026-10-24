<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlanVersion extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'subscription_plan_id', 'version', 'name', 'slug', 'description',
        'monthly_price_paisa', 'yearly_price_paisa', 'module_entitlements',
        'max_turfs', 'max_branches', 'max_staff', 'published_by_platform_admin_id', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'module_entitlements' => 'array',
            'monthly_price_paisa' => 'integer',
            'yearly_price_paisa' => 'integer',
            'max_turfs' => 'integer',
            'max_branches' => 'integer',
            'max_staff' => 'integer',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
