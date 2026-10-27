<?php

namespace App\Console\Commands;

use App\Models\CredentialDelivery;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReconcileCredentialDeliveryClaims extends Command
{
    protected $signature = 'credentials:reconcile-delivery-claims {--lease=15 : Minutes before a processing claim is considered abandoned}';

    protected $description = 'Mark abandoned credential delivery claims as unknown without sending a new message';

    public function handle(AuditLogger $auditLogger): int
    {
        $cutoff = Carbon::now()->subMinutes(max(1, (int) $this->option('lease')));
        $count = 0;

        CredentialDelivery::query()
            ->where('status', CredentialDelivery::STATUS_PROCESSING)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<', $cutoff)
            ->pluck('id')
            ->each(function (string $deliveryId) use ($auditLogger, &$count): void {
                DB::transaction(function () use ($deliveryId, $auditLogger, &$count): void {
                    $delivery = CredentialDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
                    if (! $delivery || $delivery->status !== CredentialDelivery::STATUS_PROCESSING || ! $delivery->claimed_at || $delivery->claimed_at->greaterThan(Carbon::now()->subMinutes(max(1, (int) $this->option('lease'))))) {
                        return;
                    }

                    $delivery->update([
                        'status' => CredentialDelivery::STATUS_UNKNOWN,
                        'last_error_code' => 'processing_lease_expired',
                        'claim_token' => null,
                        'claimed_at' => null,
                    ]);
                    $auditLogger->record('owner.credential.delivery.lease_expired', metadata: ['delivery_id' => $delivery->id]);
                    $count++;
                });
            });

        $this->info("Marked {$count} abandoned credential delivery claim(s) as unknown.");
        return self::SUCCESS;
    }
}
