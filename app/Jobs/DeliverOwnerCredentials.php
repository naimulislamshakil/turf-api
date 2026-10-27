<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Mail\OwnerCredentialsMail;
use App\Models\CredentialDelivery;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeliverOwnerCredentials implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $deliveryId,
        public readonly string $encryptedPayload,
        public readonly ?string $credentialGeneration = null,
    ) {}

    private ?string $claimToken = null;

    public function handle(MailManager $mailManager, SmsGateway $smsGateway): void
    {
        $claim = $this->claim();
        if ($claim === null) {
            return;
        }

        $this->claimToken = $claim['claim_token'];

        try {
            $credentials = json_decode(Crypt::decryptString($claim['encrypted_payload']), true, 512, JSON_THROW_ON_ERROR);
            $this->validatePayload($credentials, $claim);
            $result = $this->sendExternally($claim['channel'], $credentials, $mailManager, $smsGateway);
            $this->finalize($result['status'], $result['error_code'] ?? null, $result['provider_reference'] ?? null);
        } catch (Throwable $exception) {
            $this->finalize(CredentialDelivery::STATUS_UNKNOWN, 'delivery_outcome_unknown', null);
            Log::warning('Owner credential delivery outcome is unknown.', ['delivery_id' => $this->deliveryId, 'error' => $exception::class]);
        }
    }

    /** @return array<string, string> */
    private function claim(): ?array
    {
        return DB::transaction(function (): ?array {
            $delivery = CredentialDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== CredentialDelivery::STATUS_QUEUED) {
                return null;
            }

            if (! $delivery->credential_generation || ($this->credentialGeneration !== null && $delivery->credential_generation !== $this->credentialGeneration)) {
                $delivery->update(['status' => CredentialDelivery::STATUS_UNKNOWN, 'last_error_code' => 'credential_generation_unverified']);
                return null;
            }

            $owner = User::query()->whereKey($delivery->user_id)->first();
            if (! $owner || ! $owner->must_change_password || ! $owner->temporary_password_expires_at || $owner->temporary_password_expires_at->isPast()) {
                $delivery->update(['status' => CredentialDelivery::STATUS_FAILED, 'last_error_code' => 'credential_expired']);
                return null;
            }

            if (! $delivery->encrypted_payload) {
                $delivery->update(['status' => CredentialDelivery::STATUS_FAILED, 'last_error_code' => 'credential_payload_missing']);
                return null;
            }

            $claimToken = (string) Str::uuid();
            $delivery->update([
                'status' => CredentialDelivery::STATUS_PROCESSING,
                'claim_token' => $claimToken,
                'claimed_at' => now(),
                'attempts' => $delivery->attempts + 1,
                'last_error_code' => null,
            ]);

            return [
                'claim_token' => $claimToken,
                'channel' => $delivery->channel,
                'credential_generation' => $delivery->credential_generation,
                'encrypted_payload' => $delivery->encrypted_payload,
            ];
        });
    }

    /** @param array<string, mixed> $credentials */
    private function validatePayload(array $credentials, array $claim): void
    {
        if (($credentials['credential_generation'] ?? null) !== $claim['credential_generation']) {
            throw new \RuntimeException('Credential generation mismatch.');
        }

        if (blank($credentials['temporary_password'] ?? null) || blank($credentials['expires_at'] ?? null)) {
            throw new \RuntimeException('Credential payload is incomplete.');
        }

        if (now()->greaterThanOrEqualTo(now()->parse($credentials['expires_at']))) {
            throw new \RuntimeException('Credential has expired.');
        }
    }

    /** @param array<string, mixed> $credentials */
    private function sendExternally(string $channel, array $credentials, MailManager $mailManager, SmsGateway $smsGateway): array
    {
        if ($channel === CredentialDelivery::CHANNEL_EMAIL) {
            $mailer = (string) config('mail.default');
            if (in_array($mailer, ['log', 'array'], true)) {
                return ['status' => CredentialDelivery::STATUS_UNAVAILABLE, 'error_code' => 'mail_provider_not_configured'];
            }

            $mailManager->mailer($mailer)->to($credentials['email'])->send(new OwnerCredentialsMail(
                $credentials['owner_name'], $credentials['business_name'], $credentials['username'],
                $credentials['temporary_password'], $credentials['expires_at'],
            ));

            return ['status' => CredentialDelivery::STATUS_ACCEPTED];
        }

        return $smsGateway->send($credentials['phone'], sprintf(
            'Your %s username is %s. Temporary password: %s. Expires: %s. Change it after first login.',
            $credentials['business_name'], $credentials['username'], $credentials['temporary_password'], $credentials['expires_at'],
        ));
    }

    private function finalize(string $status, ?string $errorCode, ?string $providerReference): void
    {
        if (! $this->claimToken) {
            return;
        }

        DB::transaction(function () use ($status, $errorCode, $providerReference): void {
            $delivery = CredentialDelivery::query()
                ->whereKey($this->deliveryId)
                ->where('status', CredentialDelivery::STATUS_PROCESSING)
                ->where('claim_token', $this->claimToken)
                ->lockForUpdate()
                ->first();

            if (! $delivery) {
                app(AuditLogger::class)->record('owner.credential.delivery.late_outcome_ignored', metadata: ['delivery_id' => $this->deliveryId]);
                return;
            }

            $delivery->update([
                'status' => $status,
                'last_error_code' => $errorCode,
                'provider_reference' => $providerReference,
                'sent_at' => $status === CredentialDelivery::STATUS_ACCEPTED ? now() : null,
                'encrypted_payload' => $status === CredentialDelivery::STATUS_ACCEPTED ? null : $delivery->encrypted_payload,
                'claim_token' => null,
                'claimed_at' => null,
            ]);

            app(AuditLogger::class)->record('owner.credential.delivery.'.$status, metadata: [
                'delivery_id' => $delivery->id,
                'channel' => $delivery->channel,
                'business_id' => $delivery->business_id,
            ]);
        });
    }

    public function failed(Throwable $exception): void
    {
        if (! $this->claimToken) {
            return;
        }

        CredentialDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', CredentialDelivery::STATUS_PROCESSING)
            ->where('claim_token', $this->claimToken)
            ->update([
                'status' => CredentialDelivery::STATUS_UNKNOWN,
                'last_error_code' => 'delivery_worker_failed',
                'claim_token' => null,
                'claimed_at' => null,
            ]);

        Log::warning('Owner credential delivery worker failed.', ['delivery_id' => $this->deliveryId]);
    }
}
