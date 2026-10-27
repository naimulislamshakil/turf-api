<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CredentialDelivery extends Model
{
    use HasUlids;

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_SMS = 'sms';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = ['user_id', 'business_id', 'credential_generation', 'channel', 'destination_hash', 'encrypted_payload', 'status', 'claim_token', 'claimed_at', 'attempts', 'last_error_code', 'provider_reference', 'sent_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'claimed_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
