<?php

namespace App\Jobs;

use App\Contracts\SmsGateway;
use App\Mail\OwnerCredentialsMail;
use App\Models\CredentialDelivery;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverOwnerCredentials implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly string $deliveryId, public readonly string $encryptedPayload) {}

    public function handle(MailManager $mailManager, SmsGateway $smsGateway): void
    {
        DB::transaction(function () use ($mailManager, $smsGateway): void {
            $delivery = CredentialDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $delivery || in_array($delivery->status, [CredentialDelivery::STATUS_ACCEPTED, CredentialDelivery::STATUS_SUPERSEDED], true)) {
                return;
            }

            $delivery->increment('attempts');
            try {
                $encryptedPayload = $this->encryptedPayload ?: $delivery->encrypted_payload;
            if (! $encryptedPayload) {
                throw new \RuntimeException('Owner credential delivery payload unavailable.');
            }
            $credentials = json_decode(Crypt::decryptString($encryptedPayload), true, 512, JSON_THROW_ON_ERROR);
            $status = null;
            $errorCode = null;
            $providerReference = null;

            if ($delivery->channel === CredentialDelivery::CHANNEL_EMAIL) {
            $mailer = (string) config('mail.default');
            if (in_array($mailer, ['log', 'array'], true)) {
                $status = CredentialDelivery::STATUS_UNAVAILABLE;
                $errorCode = 'mail_provider_not_configured';
            } else {
                $mailManager->mailer($mailer)->to($credentials['email'])->send(new OwnerCredentialsMail(
                    $credentials['owner_name'], $credentials['business_name'], $credentials['username'],
                    $credentials['temporary_password'], $credentials['expires_at'],
                ));
                $status = CredentialDelivery::STATUS_ACCEPTED;
            }
            } else {
            $result = $smsGateway->send($credentials['phone'], sprintf(
                'Your %s username is %s. Temporary password: %s. Expires: %s. Change it after first login.',
                $credentials['business_name'], $credentials['username'], $credentials['temporary_password'], $credentials['expires_at'],
            ));
            $status = $result['status'];
            $errorCode = $result['error_code'] ?? null;
            $providerReference = $result['provider_reference'] ?? null;
            }

            $delivery->update(['status' => $status, 'last_error_code' => $errorCode, 'provider_reference' => $providerReference, 'sent_at' => $status === CredentialDelivery::STATUS_ACCEPTED ? now() : null, 'encrypted_payload' => $status === CredentialDelivery::STATUS_ACCEPTED ? null : $delivery->encrypted_payload]);
            app(AuditLogger::class)->record('owner.credential.delivery.'.$status, metadata: ['delivery_id' => $delivery->id, 'channel' => $delivery->channel, 'business_id' => $delivery->business_id]);
            } catch (Throwable) {
                throw new \RuntimeException('Owner credential delivery failed.');
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        CredentialDelivery::whereKey($this->deliveryId)->update(['status' => CredentialDelivery::STATUS_FAILED, 'last_error_code' => 'delivery_exception']);
        Log::warning('Owner credential delivery failed.', ['delivery_id' => $this->deliveryId]);
    }
}
