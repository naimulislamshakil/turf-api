<?php

namespace App\Services;

use App\Jobs\DeliverOwnerCredentials;
use App\Models\Business;
use App\Models\CredentialDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class OwnerCredentialDelivery
{
    public function queue(User $owner, Business $business, string $temporaryPassword): void
    {
        $expiresAt = $owner->temporary_password_expires_at?->toISOString() ?? now()->addDay()->toISOString();
        $payload = Crypt::encryptString(json_encode([
            'owner_name' => $owner->name,
            'business_name' => $business->name,
            'username' => $owner->username,
            'email' => $owner->email,
            'phone' => $owner->phone,
            'temporary_password' => $temporaryPassword,
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR));

        foreach ([CredentialDelivery::CHANNEL_EMAIL => $owner->email, CredentialDelivery::CHANNEL_SMS => $owner->phone] as $channel => $destination) {
            $delivery = CredentialDelivery::create([
                'user_id' => $owner->id,
                'business_id' => $business->id,
                'channel' => $channel,
                'destination_hash' => hash('sha256', (string) $destination),
                'encrypted_payload' => $payload,
                'status' => CredentialDelivery::STATUS_QUEUED,
            ]);
            DeliverOwnerCredentials::dispatch($delivery->id, $payload)->afterCommit();
        }
    }
}
