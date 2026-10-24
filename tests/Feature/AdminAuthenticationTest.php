<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_and_view_profile(): void
    {
        $admin = $this->admin();

        $response = $this->frontend('198.51.100.10')->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'correct-password']);

        $response->assertOk()->assertJsonPath('data.email', $admin->email)->assertJsonStructure(['data' => ['id', 'name', 'email', 'is_active']])->assertJsonMissing(['token']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login.succeeded']);
        $this->frontend()->getJson('/api/v1/admin/auth/me')->assertOk();
    }

    public function test_invalid_credentials_are_rejected_without_leaking_secrets(): void
    {
        $admin = $this->admin();

        $this->frontend('198.51.100.11')->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login.failed']);
    }

    public function test_inactive_admin_is_rejected(): void
    {
        $admin = $this->admin(['is_active' => false]);

        $this->frontend('198.51.100.12')->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login.inactive']);
    }

    public function test_admin_endpoints_reject_unauthorized_and_non_admin_users(): void
    {
        $this->frontend()->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->frontend()->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_current_session(): void
    {
        $admin = $this->admin();

        $this->frontend('198.51.100.13')->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'correct-password'])->assertOk();
        $this->frontend('198.51.100.13')->postJson('/api/v1/admin/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->frontend()->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_inactive_authenticated_admin_is_rejected_and_session_is_removed(): void
    {
        $admin = $this->admin();
        $this->frontend('198.51.100.14')->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'correct-password'])->assertOk();
        $admin->update(['is_active' => false]);
        Auth::forgetGuards();

        $this->frontend()->getJson('/api/v1/admin/auth/me')->assertForbidden();
        Auth::forgetGuards();
        $this->frontend()->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        $admin = $this->admin();
        \Illuminate\Support\Facades\RateLimiter::clear('admin-login:'.$admin->email.'|198.51.100.43');
        \Illuminate\Support\Facades\RateLimiter::clear('admin-login|198.51.100.43');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->frontend('198.51.100.43')->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong-password'])->assertStatus(422);
        }

        $this->frontend('198.51.100.43')->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.login.rate_limited']);
    }

    public function test_stateful_csrf_protection_is_configured(): void
    {
        $this->assertSame(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            config('sanctum.middleware.validate_csrf_token'),
        );
        $this->withHeader('Origin', 'http://localhost:3000')->get('/sanctum/csrf-cookie')->assertNoContent();
    }

    private function frontend(string $ip = '127.0.0.1')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->withHeader('Origin', 'http://localhost:3000');
    }

    private function admin(array $overrides = []): PlatformAdmin
    {
        return PlatformAdmin::create(array_merge([
            'name' => 'Primary Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-password'),
            'is_active' => true,
        ], $overrides));
    }
}
