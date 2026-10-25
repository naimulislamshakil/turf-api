<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OwnerCredentialsMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $ownerName,
        public readonly string $businessName,
        public readonly string $username,
        public readonly string $temporaryPassword,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your account credentials for '.$this->businessName);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.owner-credentials');
    }

    public function headers(): array
    {
        return [];
    }
}
