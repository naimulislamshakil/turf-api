<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'admin:create {--name=} {--email=}';
    protected $description = 'Provision a platform administrator securely.';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = strtolower($this->option('email') ?: $this->ask('Email'));
        $this->validateInput($name, $email);
        $password = $this->secret('Password (input hidden)');
        $confirmation = $this->secret('Confirm password (input hidden)');

        if (! $password || $password !== $confirmation || strlen($password) < 8 || strlen($password) > 128) {
            $this->error('Passwords must match and be between 8 and 128 characters.');
            return self::FAILURE;
        }

        $admin = PlatformAdmin::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true]);
        $this->info("Platform administrator {$admin->email} created.");
        return self::SUCCESS;
    }

    private function validateInput(string $name, string $email): void
    {
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || PlatformAdmin::where('email', $email)->exists()) {
            throw new \InvalidArgumentException('Name/email is invalid or the email is already registered.');
        }
    }
}
