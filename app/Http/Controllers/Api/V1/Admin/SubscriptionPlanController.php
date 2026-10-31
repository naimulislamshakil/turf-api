<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\SubscriptionPlanVersion;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubscriptionPlanController extends Controller
{
    private const MODULES = ['turf', 'restaurant', 'shop', 'equipment_rental'];

    public function index()
    {
        return response()->json(['data' => SubscriptionPlan::query()->orderBy('name')->get()->map(fn (SubscriptionPlan $plan) => $this->plan($plan))->values()]);
    }

    public function store(Request $request, AuditLogger $auditLogger)
    {
        $data = $this->validated($request);
        $plan = SubscriptionPlan::create(array_merge($data, [
            'status' => SubscriptionPlan::STATUS_DRAFT,
            'current_version' => 1,
        ]));
        $auditLogger->record('admin.subscription_plan.created', $request->user(), $request, ['plan_id' => $plan->id]);

        return response()->json(['data' => $this->plan($plan)], 201);
    }

    public function show(SubscriptionPlan $plan)
    {
        return response()->json(['data' => $this->plan($plan->load('versions'))]);
    }

    public function update(Request $request, SubscriptionPlan $plan, AuditLogger $auditLogger)
    {
        if ($plan->status === SubscriptionPlan::STATUS_ARCHIVED) {
            throw ValidationException::withMessages(['status' => 'Archived plans cannot be edited.']);
        }

        $data = $this->validated($request, $plan);
        $data['module_entitlements'] = array_merge($plan->module_entitlements ?? [], $data['module_entitlements'] ?? []);

        DB::transaction(function () use ($plan, $data, $auditLogger, $request): void {
            $plan->fill($data);
            if ($plan->getOriginal('status') === SubscriptionPlan::STATUS_PUBLISHED) {
                $plan->status = SubscriptionPlan::STATUS_DRAFT;
                $plan->current_version = (int) $plan->getOriginal('current_version') + 1;
                $plan->published_at = null;
                $plan->archived_at = null;
                $plan->save();
                $auditLogger->record('admin.subscription_plan.revised', $request->user(), $request, ['plan_id' => $plan->id, 'version' => $plan->current_version]);
                return;
            }

            $plan->save();
            $auditLogger->record('admin.subscription_plan.updated', $request->user(), $request, ['plan_id' => $plan->id]);
        });

        return response()->json(['data' => $this->plan($plan->refresh())]);
    }

    public function publish(SubscriptionPlan $plan, Request $request, AuditLogger $auditLogger)
    {
        if (! in_array($plan->status, [SubscriptionPlan::STATUS_DRAFT, SubscriptionPlan::STATUS_ARCHIVED], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or archived plans can be published.']);
        }

        $version = DB::transaction(function () use ($plan, $request, $auditLogger): SubscriptionPlanVersion {
            $publishedAt = now();
            $version = $plan->status === SubscriptionPlan::STATUS_ARCHIVED
                ? $plan->versions()->where('version', $plan->current_version)->first()
                : null;
            if (! $version) {
                $version = SubscriptionPlanVersion::create([
                    'subscription_plan_id' => $plan->id,
                    'version' => $plan->current_version,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'monthly_price_paisa' => $plan->monthly_price_paisa,
                    'yearly_price_paisa' => $plan->yearly_price_paisa,
                    'module_entitlements' => $plan->module_entitlements,
                    'max_turfs' => $plan->max_turfs,
                    'max_branches' => $plan->max_branches,
                    'max_staff' => $plan->max_staff,
                    'published_by_platform_admin_id' => $request->user()?->getKey(),
                    'published_at' => $publishedAt,
                ]);
            }
            $plan->update(['status' => SubscriptionPlan::STATUS_PUBLISHED, 'published_at' => $publishedAt, 'archived_at' => null]);
            $auditLogger->record('admin.subscription_plan.published', $request->user(), $request, ['plan_id' => $plan->id, 'version' => $version->version]);

            return $version;
        });

        return response()->json(['data' => $this->plan($plan->refresh()->load('versions')), 'published_version' => $version->version]);
    }

    public function archive(SubscriptionPlan $plan, Request $request, AuditLogger $auditLogger)
    {
        if ($plan->status === SubscriptionPlan::STATUS_ARCHIVED) {
            throw ValidationException::withMessages(['status' => 'The plan is already archived.']);
        }

        $plan->update(['status' => SubscriptionPlan::STATUS_ARCHIVED, 'archived_at' => now()]);
        $auditLogger->record('admin.subscription_plan.archived', $request->user(), $request, ['plan_id' => $plan->id, 'version' => $plan->current_version]);

        return response()->json(['data' => $this->plan($plan->refresh())]);
    }

    private function validated(Request $request, ?SubscriptionPlan $plan = null): array
    {
        $rules = [
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:120'],
            'slug' => [$plan ? 'sometimes' : 'required', 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('subscription_plans', 'slug')->ignore($plan?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'monthly_price_paisa' => [$plan ? 'sometimes' : 'required', 'integer', 'min:0'],
            'yearly_price_paisa' => [$plan ? 'sometimes' : 'required', 'integer', 'min:0'],
            'modules' => [$plan ? 'sometimes' : 'required', 'array'],
            'modules.turf' => ['sometimes', 'boolean'],
            'modules.restaurant' => ['sometimes', 'boolean'],
            'modules.shop' => ['sometimes', 'boolean'],
            'modules.equipment_rental' => ['sometimes', 'boolean'],
            'max_turfs' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_branches' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_staff' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];

        $data = $request->validate($rules);
        if (array_key_exists('modules', $data)) {
            $data['module_entitlements'] = array_intersect_key($data['modules'], array_flip(self::MODULES));
            unset($data['modules']);
        }
        if ($plan && ! array_key_exists('module_entitlements', $data)) {
            $data['module_entitlements'] = $plan->module_entitlements ?? [];
        }

        return $data;
    }

    private function plan(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'status' => $plan->status,
            'monthly_price_paisa' => $plan->monthly_price_paisa,
            'yearly_price_paisa' => $plan->yearly_price_paisa,
            'modules' => $plan->module_entitlements ?? [],
            'max_turfs' => $plan->max_turfs,
            'max_branches' => $plan->max_branches,
            'max_staff' => $plan->max_staff,
            'version' => $plan->current_version,
            'published_at' => $plan->published_at?->toISOString(),
            'archived_at' => $plan->archived_at?->toISOString(),
            'versions' => $plan->relationLoaded('versions') ? $plan->versions->map(fn (SubscriptionPlanVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'published_at' => $version->published_at?->toISOString(),
            ])->values() : [],
        ];
    }
}
