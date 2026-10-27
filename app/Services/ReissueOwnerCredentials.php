<?php

namespace App\Services;

use App\Jobs\DeliverOwnerCredentials;
use App\Models\Business;
use App\Models\CredentialDelivery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class ReissueOwnerCredentials
{
    public function __construct(
        private AuditLogger $auditLogger,
        private OwnerTemporaryPasswordGenerator $passwordGenerator,
    ) {}

    /** @return array{owner: User, deliveries: array<int, CredentialDelivery>} */
    public function execute(Business $business, Request $request, string $reason): array
    {
        return DB::transaction(function () use ($business, $request, $reason): array {
            $lockedBusiness = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();
            $ownerId = DB::table('business_owners')->where('business_id', $lockedBusiness->id)->orderBy('created_at')->value('user_id');
            $owner = $ownerId ? User::query()->whereKey($ownerId)->lockForUpdate()->firstOrFail() : null;

            if (! $owner || ! $owner->must_change_password) {
                throw ValidationException::withMessages(['owner' => 'This owner is not eligible for temporary credential reissue.']);
            }

            $temporaryPassword = $this->passwordGenerator->generate();
            $expiresAt = now()->addDay();
            $credentialGeneration = (string) Str::uuid();
            $owner->forceFill([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
                'temporary_password_expires_at' => $expiresAt,
                'password_changed_at' => null,
            ])->save();

            CredentialDelivery::query()
                ->where('business_id', $lockedBusiness->id)
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->get()
                ->each(fn (CredentialDelivery $delivery) => $delivery->update([
                    'status' => CredentialDelivery::STATUS_SUPERSEDED,
                    'encrypted_payload' => null,
                    'last_error_code' => 'credential_reissued',
                    'provider_reference' => null,
                    'sent_at' => null,
                ]));

            $payload = Crypt::encryptString(json_encode([
                'owner_name' => $owner->name,
                'business_name' => $lockedBusiness->name,
                'username' => $owner->username,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'temporary_password' => $temporaryPassword,
                'expires_at' => $expiresAt->toISOString(),
                'credential_generation' => $credentialGeneration,
            ], JSON_THROW_ON_ERROR));

            $deliveries = [];
            foreach ([CredentialDelivery::CHANNEL_EMAIL => $owner->email, CredentialDelivery::CHANNEL_SMS => $owner->phone] as $channel => $destination) {
                if (blank($destination)) {
                    continue;
                }
                $delivery = CredentialDelivery::create([
                    'user_id' => $owner->id,
                    'business_id' => $lockedBusiness->id,
                    'credential_generation' => $credentialGeneration,
                    'channel' => $channel,
                    'destination_hash' => hash('sha256', (string) $destination),
                    'encrypted_payload' => $payload,
                    'status' => CredentialDelivery::STATUS_QUEUED,
                ]);
                DeliverOwnerCredentials::dispatch($delivery->id, $payload, $credentialGeneration)->afterCommit();
                $deliveries[] = $delivery;
            }

            $this->auditLogger->record('owner.credential.reissued', $request->user(), $request, [
                'owner_id' => $owner->id,
                'business_id' => $lockedBusiness->id,
                'channels' => array_values(array_map(fn (CredentialDelivery $delivery) => $delivery->channel, $deliveries)),
                'reason' => $reason,
                'reissued_at' => now()->toISOString(),
            ]);

            return ['owner' => $owner->fresh(), 'deliveries' => $deliveries];
        });
    }
}
