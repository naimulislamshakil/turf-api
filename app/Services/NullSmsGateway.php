<?php

namespace App\Services;

use App\Contracts\SmsGateway;

class NullSmsGateway implements SmsGateway
{
    public function send(string $phone, string $message): array
    {
        return ['status' => 'unavailable', 'error_code' => 'sms_provider_not_configured'];
    }
}
