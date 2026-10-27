<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverOwnerCredentials;
use App\Models\Business;
use App\Models\CredentialDelivery;
use App\Models\ManualSubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OwnerCredentialDelivery;
use App\Services\OwnerTemporaryPasswordGenerator;
use App\Services\ReissueOwnerCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $query = Business::query()->with(['owners:id,name,email,must_change_password', 'onboardingPlan:id,name,slug,status'])->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn ($business) => $business->where('name', 'like', $search)->orWhere('slug', 'like', $search)->orWhere('phone', 'like', $search));
        }

        return response()->json(['data' => $query->get()->map(fn (Business $business) => $this->business($business))->values()]);
    }

    public function owners()
    {
        return response()->json(['data' => User::query()->select(['id', 'name', 'email', 'phone'])->orderBy('name')->orderBy('email')->get()]);
    }

    public function retryCredentialDelivery(Request $request, CredentialDelivery $delivery, AuditLogger $auditLogger)
    {
        $result = DB::transaction(function () use ($request, $delivery, $auditLogger): array {
            $locked = CredentialDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $owner = User::query()->whereKey($locked->user_id)->firstOrFail();
            if ($locked->status === CredentialDelivery::STATUS_ACCEPTED) {
                return ['delivery' => $locked, 'idempotent' => true];
            }
            if ($locked->status === CredentialDelivery::STATUS_QUEUED) {
                return ['delivery' => $locked, 'idempotent' => true];
            }
            if ($locked->status === CredentialDelivery::STATUS_PROCESSING) {
                throw ValidationException::withMessages(['delivery' => 'This credential delivery is currently being processed.']);
            }
            if ($locked->status === CredentialDelivery::STATUS_SUPERSEDED || ! $locked->credential_generation || ! $locked->encrypted_payload || ! $owner->must_change_password || ! $owner->temporary_password_expires_at || $owner->temporary_password_expires_at->isPast()) {
                throw ValidationException::withMessages(['delivery' => 'This temporary credential is no longer eligible for delivery retry.']);
            }
            $duplicateDeliveryWarning = $locked->status === CredentialDelivery::STATUS_UNKNOWN;
            $locked->update(['status' => CredentialDelivery::STATUS_QUEUED, 'claim_token' => null, 'claimed_at' => null, 'last_error_code' => null]);
            DeliverOwnerCredentials::dispatch($locked->id, $locked->encrypted_payload, $locked->credential_generation)->afterCommit();
            $auditLogger->record('owner.credential.delivery.retry_queued', $request->user(), $request, ['delivery_id' => $locked->id, 'business_id' => $locked->business_id, 'channel' => $locked->channel]);
            return ['delivery' => $locked->refresh(), 'idempotent' => false, 'duplicate_delivery_warning' => $duplicateDeliveryWarning];
        });

        return response()->json(['data' => ['id' => $result['delivery']->id, 'channel' => $result['delivery']->channel, 'status' => $result['delivery']->status], 'idempotent' => $result['idempotent'], 'duplicate_delivery_warning' => $result['duplicate_delivery_warning'] ?? false]);
    }

    public function reissueCredential(Request $request, Business $business, ReissueOwnerCredentials $reissueOwnerCredentials)
    {
        $data = $request->validate([
            'confirmed' => ['accepted'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $result = $reissueOwnerCredentials->execute($business, $request, $data['reason']);

        return response()->json(['data' => [
            'owner_id' => $result['owner']->id,
            'credential_expires_at' => $result['owner']->temporary_password_expires_at?->toISOString(),
            'credential_deliveries' => collect($result['deliveries'])->map(fn (CredentialDelivery $delivery) => [
                'id' => $delivery->id,
                'channel' => $delivery->channel,
                'status' => $delivery->status,
            ])->values(),
        ]]);
    }

    public function store(Request $request, AuditLogger $auditLogger, OwnerTemporaryPasswordGenerator $passwordGenerator)
    {
        $data = $this->validated($request);
        $logoPath = null;
        if ($request->hasFile('logo')) {
            $logo = $request->file('logo');
            $logoPath = Storage::disk('public')->putFileAs('business-logos', $logo, Str::ulid().'.'.$logo->extension());
        }

        try {
            $business = DB::transaction(function () use ($data, $request, $auditLogger, $logoPath, $passwordGenerator): Business {
            $ownerName = $data['owner_name'];
            $ownerUsername = $data['owner_username'];
            $ownerEmail = $data['owner_email'];
            $ownerPhone = $data['owner_phone'];
            $credentialGeneration = (string) Str::uuid();
            unset($data['owner_name'], $data['owner_username'], $data['owner_email'], $data['owner_phone'], $data['logo']);
            $temporaryPassword = $passwordGenerator->generate();
            $owner = User::create([
                'name' => $ownerName,
                'username' => $ownerUsername,
                'email' => $ownerEmail,
                'phone' => $ownerPhone,
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'temporary_password_expires_at' => now()->addDay(),
            ]);
            $business = Business::create(array_merge($data, ['logo_path' => $logoPath, 'status' => Business::STATUS_PENDING]));
            $business->owners()->attach($owner->id, ['id' => (string) Str::ulid()]);
            app(OwnerCredentialDelivery::class)->queue($owner, $business, $temporaryPassword, $credentialGeneration);
            $auditLogger->record('admin.business.created', $request->user(), $request, [
                'business_id' => $business->id,
                'owner_id' => $owner->id,
                'onboarding_plan_id' => $business->onboarding_plan_id,
                'status' => $business->status,
            ]);

                return $business;
            });
        } catch (\Throwable $exception) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }
            throw $exception;
        }

        return response()->json(['data' => $this->business($business->load(['owners:id,name,email', 'onboardingPlan:id,name,slug,status', 'credentialDeliveries']))], 201);
    }

    public function show(Business $business)
    {
        return response()->json(['data' => $this->business($business->load(['owners:id,name,email', 'onboardingPlan:id,name,slug,status', 'credentialDeliveries']))]);
    }

    public function update(Request $request, Business $business, AuditLogger $auditLogger)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('businesses', 'slug')->ignore($business->id)],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'district_city' => ['required', 'string', 'max:120'],
        ]);

        $updated = DB::transaction(function () use ($business, $data, $request, $auditLogger): Business {
            $locked = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $locked->fill($data);
            $changedFields = array_keys($locked->getDirty());
            $locked->save();
            $auditLogger->record('admin.business.updated', $request->user(), $request, ['business_id' => $locked->id, 'fields' => $changedFields]);
            return $locked->fresh()->load(['owners:id,name,email', 'onboardingPlan:id,name,slug,status']);
        });

        return response()->json(['data' => $this->business($updated)]);
    }

    public function destroy(Request $request, Business $business, AuditLogger $auditLogger)
    {
        $data = $request->validate(['deletion_reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($business, $data, $request, $auditLogger): void {
            $locked = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            if ($locked->subscriptions()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['business' => 'Businesses with an active subscription cannot be deleted.']);
            }
            if (ManualSubscriptionPayment::query()->where('business_id', $locked->id)->where('status', ManualSubscriptionPayment::STATUS_PENDING)->exists()) {
                throw ValidationException::withMessages(['business' => 'Businesses with pending subscription payments cannot be deleted.']);
            }
            $locked->delete();
            $auditLogger->record('admin.business.deleted', $request->user(), $request, ['business_id' => $locked->id, 'deletion_reason' => $data['deletion_reason']]);
        });

        return response()->json(['message' => 'Business deleted.']);
    }

    private function validated(Request $request): array
    {
        if ($request->filled('owner_username')) {
            $request->merge(['owner_username' => Str::lower(trim((string) $request->input('owner_username')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('businesses', 'slug')],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'district_city' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:160'],
            'owner_username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', Rule::unique('users', 'username')],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_phone' => ['required', 'string', 'max:40', Rule::unique('users', 'phone')],
            'onboarding_plan_id' => ['sometimes', 'nullable', 'string', Rule::exists('subscription_plans', 'id')->where(fn ($query) => $query->where('status', SubscriptionPlan::STATUS_PUBLISHED))],
            'logo' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ]);
        $data['owner_username'] = Str::lower(trim($data['owner_username']));
        return $data;
    }

    private function uniqueUsername(string $slug): string
    {
        do {
            $username = 'owner-'.$slug.'-'.Str::lower(Str::random(6));
        } while (User::where('username', $username)->exists());

        return $username;
    }

    private function business(Business $business): array
    {
        $owner = $business->owners->first();

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'phone' => $business->phone,
            'email' => $business->email,
            'address' => $business->address,
            'district_city' => $business->district_city,
            'status' => $business->status,
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name, 'email' => $owner->email, 'requires_password_change' => $owner->must_change_password, 'credential_expires_at' => $owner->temporary_password_expires_at?->toISOString()] : null,
            'can_reissue_credentials' => (bool) ($owner?->must_change_password),
            'owners' => $business->owners->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->values(),
            'onboarding_plan' => $business->onboardingPlan ? ['id' => $business->onboardingPlan->id, 'name' => $business->onboardingPlan->name, 'slug' => $business->onboardingPlan->slug, 'status' => $business->onboardingPlan->status] : null,
            'logo_url' => $business->logo_path ? Storage::disk('public')->url($business->logo_path) : null,
            'credential_deliveries' => $business->relationLoaded('credentialDeliveries') ? $business->credentialDeliveries->map(fn ($delivery) => ['id' => $delivery->id, 'channel' => $delivery->channel, 'status' => $delivery->status, 'error_code' => $delivery->last_error_code, 'provider_reference' => $delivery->provider_reference, 'updated_at' => $delivery->updated_at?->toISOString()])->values() : [],
            'created_at' => $business->created_at?->toISOString(),
            'updated_at' => $business->updated_at?->toISOString(),
        ];
    }
}
