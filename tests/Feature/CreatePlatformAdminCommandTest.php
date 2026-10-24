<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreatePlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seven_character_password_is_rejected(): void
    {
        $this->runProvisioning('seven@example.com', 'shortpw', 'shortpw')
            ->assertExitCode(1)
            ->expectsOutput('Passwords must match and be between 8 and 128 characters.');

        $this->assertDatabaseMissing('platform_admins', ['email' => 'seven@example.com']);
    }

    public function test_eight_character_password_is_accepted(): void
    {
        $this->runProvisioning('eight@example.com', 'shortpwd', 'shortpwd')
            ->assertExitCode(0);

        $this->assertProvisionedAdmin('eight@example.com', 'shortpwd');
    }

    public function test_one_hundred_twenty_eight_character_password_is_accepted(): void
    {
        $password = str_repeat('a', 128);

        $this->runProvisioning('maximum@example.com', $password, $password)
            ->assertExitCode(0);

        $this->assertProvisionedAdmin('maximum@example.com', $password);
    }

    public function test_one_hundred_twenty_nine_character_password_is_rejected(): void
    {
        $password = str_repeat('a', 129);

        $this->runProvisioning('too-long@example.com', $password, $password)
            ->assertExitCode(1)
            ->expectsOutput('Passwords must match and be between 8 and 128 characters.');

        $this->assertDatabaseMissing('platform_admins', ['email' => 'too-long@example.com']);
    }

    public function test_mismatched_password_confirmation_is_rejected(): void
    {
        $this->runProvisioning('mismatch@example.com', 'shortpwd', 'different')
            ->assertExitCode(1)
            ->expectsOutput('Passwords must match and be between 8 and 128 characters.');

        $this->assertDatabaseMissing('platform_admins', ['email' => 'mismatch@example.com']);
    }

    private function runProvisioning(string $email, string $password, string $confirmation)
    {
        return $this->artisan('admin:create', [
            '--name' => 'Test Admin',
            '--email' => $email,
        ])
            ->expectsQuestion('Password (input hidden)', $password)
            ->expectsQuestion('Confirm password (input hidden)', $confirmation);
    }

    private function assertProvisionedAdmin(string $email, string $password): void
    {
        $admin = PlatformAdmin::where('email', $email)->firstOrFail();

        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertNotSame($password, $admin->password);
    }
}
