<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name', 'slug', 'description', 'status', 'monthly_price_paisa', 'yearly_price_paisa',
        'module_entitlements', 'max_turfs', 'max_branches', 'max_staff', 'current_version',
        'published_at', 'archived_at',
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
            'current_version' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function versions()
    {
        return $this->hasMany(SubscriptionPlanVersion::class)->orderByDesc('version');
    }
}
