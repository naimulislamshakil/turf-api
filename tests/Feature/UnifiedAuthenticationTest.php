<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnifiedAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_use_unified_login_and_gets_admin_destination(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Unified Admin', 'username' => 'unified-admin', 'email' => 'unified-admin@example.com', 'password' => Hash::make('correct-password'), 'is_active' => true]);

        $this->frontend()->postJson('/api/v1/auth/login', ['login' => $admin->email, 'password' => 'correct-password'])
            ->assertOk()
            ->assertJsonPath('data.role', 'platform-admin')
            ->assertJsonPath('data.redirect_to', '/admin')
            ->assertJsonMissing(['password']);

        $this->frontend()->getJson('/api/v1/admin/auth/me')->assertOk();
    }

    public function test_owner_can_use_unified_login_and_temporary_password_redirect_is_preserved(): void
    {
        $owner = User::create(['name' => 'Unified Owner', 'username' => 'unified-owner', 'email' => 'unified-owner@example.com', 'phone' => '+8801912345678', 'password' => 'temporary-secret', 'must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()]);

        $this->frontend()->postJson('/api/v1/auth/login', ['login' => $owner->username, 'password' => 'temporary-secret'])
            ->assertOk()
            ->assertJsonPath('data.role', 'business-owner')
            ->assertJsonPath('data.requires_password_change', true)
            ->assertJsonPath('data.redirect_to', '/owner/change-password')
            ->assertJsonMissing(['password']);

        $this->frontend()->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_duplicate_identifier_across_guards_is_rejected_without_selecting_a_guard(): void
    {
        PlatformAdmin::create(['name' => 'Duplicate Admin', 'username' => 'duplicate-admin', 'email' => 'duplicate@example.com', 'password' => Hash::make('correct-password'), 'is_active' => true]);
        User::create(['name' => 'Duplicate Owner', 'username' => 'duplicate-owner', 'email' => 'duplicate@example.com', 'phone' => '+8801912345678', 'password' => 'correct-password']);

        $this->frontend()->postJson('/api/v1/auth/login', ['login' => 'duplicate@example.com', 'password' => 'correct-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');

        $this->assertFalse(Auth::guard('platform_admin')->check());
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_unified_logout_invalidates_the_session(): void
    {
        $admin = PlatformAdmin::create(['name' => 'Logout Admin', 'username' => 'logout-admin', 'email' => 'logout@example.com', 'password' => Hash::make('correct-password'), 'is_active' => true]);

        $this->frontend()->postJson('/api/v1/auth/login', ['login' => $admin->email, 'password' => 'correct-password'])->assertOk();
        $this->frontend()->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->frontend()->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    private function frontend()
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.80'])->withHeader('Origin', 'http://localhost:3000');
    }
}
