<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnerAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_owner_password_requires_change_and_normal_access_is_blocked(): void
    {
        $owner = $this->owner(['password' => 'temporary-secret', 'must_change_password' => true, 'temporary_password_expires_at' => now()->addDay()]);
        $this->ownerSession()->postJson('/api/v1/owner/auth/login', ['login' => $owner->username, 'password' => 'temporary-secret'])->assertOk()->assertJsonPath('data.requires_password_change', true)->assertJsonMissing(['password']);
        $this->actingAs($owner, 'web')->withHeader('Origin', 'http://localhost:3000')->getJson('/api/v1/owner/auth/profile')->assertForbidden()->assertJsonPath('requires_password_change', true);
        $this->actingAs($owner, 'web')->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/owner/auth/password', ['current_password' => 'temporary-secret', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertOk()->assertJsonPath('data.requires_password_change', false)->assertJsonMissing(['password']);
        $this->assertFalse($owner->fresh()->must_change_password);
        $this->assertNull($owner->fresh()->temporary_password_expires_at);
        $this->actingAs($owner->fresh(), 'web')->withHeader('Origin', 'http://localhost:3000')->getJson('/api/v1/owner/auth/profile')->assertOk();
    }

    public function test_expired_temporary_password_is_rejected(): void
    {
        $owner = $this->owner(['password' => 'temporary-secret', 'must_change_password' => true, 'temporary_password_expires_at' => now()->subMinute()]);
        $this->ownerSession()->postJson('/api/v1/owner/auth/login', ['login' => $owner->email, 'password' => 'temporary-secret'])->assertUnprocessable()->assertJsonValidationErrors('login');
    }

    public function test_password_is_hashed_and_plaintext_never_appears_in_audit_records(): void
    {
        $owner = $this->owner(['password' => 'temporary-secret', 'must_change_password' => true]);
        $this->ownerSession()->postJson('/api/v1/owner/auth/login', ['login' => $owner->username, 'password' => 'temporary-secret']);
        $this->actingAs($owner, 'web')->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/owner/auth/password', ['current_password' => 'temporary-secret', 'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password'])->assertOk();
        $this->assertNotSame('new-secure-password', $owner->fresh()->password);
        $this->assertTrue(Hash::check('new-secure-password', $owner->fresh()->password));
        $this->assertStringNotContainsString('temporary-secret', json_encode(\App\Models\AuditLog::all()));
    }

    private function owner(array $overrides = []): User
    {
        return User::create(array_merge(['name' => 'Owner', 'username' => 'owner-test', 'email' => 'owner-test@example.com', 'phone' => '+8801912345678', 'password' => 'password'], $overrides));
    }

    private function ownerSession()
    {
        return $this->withHeader('Origin', 'http://localhost:3000');
    }
}
