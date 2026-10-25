<?php

namespace App\Contracts;

interface SmsGateway
{
    /** @return array{status: 'accepted'|'rejected'|'unknown'|'unavailable', error_code?: string, provider_reference?: string|null} */
    public function send(string $phone, string $message): array;
}
