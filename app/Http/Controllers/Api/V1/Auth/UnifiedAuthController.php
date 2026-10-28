<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UnifiedAuthController extends Controller
{
    public function login(Request $request, AuditLogger $auditLogger)
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = Str::lower(trim($credentials['login']));
        $key = 'unified-login:'.$identifier.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $auditLogger->record('auth.login.rate_limited', request: $request);
            throw ValidationException::withMessages(['login' => 'Unable to sign in with the provided credentials.']);
        }

        $admins = $this->matchingAdmins($identifier);
        $owners = $this->matchingOwners($identifier);
        if ($admins->count() + $owners->count() !== 1) {
            RateLimiter::hit($key, 60);
            $auditLogger->record('auth.login.failed', request: $request, metadata: ['reason' => 'invalid_or_ambiguous_identifier']);
            throw ValidationException::withMessages(['login' => 'Unable to sign in with the provided credentials.']);
        }

        $identity = $admins->first() ?? $owners->first();
        if (! Hash::check($credentials['password'], $identity->password)) {
            RateLimiter::hit($key, 60);
            $auditLogger->record('auth.login.failed', request: $request, metadata: ['reason' => 'invalid_credentials']);
            throw ValidationException::withMessages(['login' => 'Unable to sign in with the provided credentials.']);
        }

        if ($identity instanceof PlatformAdmin) {
            if (! $identity->is_active) {
                RateLimiter::hit($key, 60);
                $auditLogger->record('auth.login.inactive', $identity, $request);
                throw ValidationException::withMessages(['login' => 'Unable to sign in with the provided credentials.']);
            }

            $role = 'platform-admin';
            $requiresPasswordChange = false;
            $destination = '/admin';
        } else {
            if ($identity->must_change_password && $identity->temporary_password_expires_at?->isPast()) {
                RateLimiter::hit($key, 60);
                $auditLogger->record('auth.login.expired_temporary_password', request: $request, metadata: ['user_id' => $identity->id]);
                throw ValidationException::withMessages(['login' => 'Unable to sign in with the provided credentials.']);
            }

            $role = 'business-owner';
            $requiresPasswordChange = $identity->must_change_password;
            $destination = $requiresPasswordChange ? '/owner/change-password' : '/owner';
        }

        Auth::guard('platform_admin')->logout();
        Auth::guard('web')->logout();
        if ($identity instanceof PlatformAdmin) {
            Auth::guard('platform_admin')->login($identity);
        } else {
            Auth::guard('web')->login($identity);
        }
        $request->session()->regenerate();
        RateLimiter::clear($key);

        if ($identity instanceof PlatformAdmin) {
            $identity->forceFill(['last_login_at' => now()])->save();
        }

        $auditLogger->record('auth.login.succeeded', $identity, $request, ['role' => $role, 'requires_password_change' => $requiresPasswordChange]);

        return response()->json(['data' => [
            'role' => $role,
            'requires_password_change' => $requiresPasswordChange,
            'redirect_to' => $destination,
            'profile' => $identity instanceof PlatformAdmin
                ? ['id' => $identity->id, 'name' => $identity->name, 'username' => $identity->username, 'email' => $identity->email, 'is_active' => $identity->is_active]
                : ['id' => $identity->id, 'name' => $identity->name, 'username' => $identity->username, 'email' => $identity->email, 'requires_password_change' => $identity->must_change_password],
        ]]);
    }

    public function me(Request $request)
    {
        $identity = $request->user();
        if ($identity instanceof PlatformAdmin) {
            return response()->json(['data' => ['role' => 'platform-admin', 'requires_password_change' => false, 'profile' => ['id' => $identity->id, 'name' => $identity->name, 'username' => $identity->username, 'email' => $identity->email, 'is_active' => $identity->is_active]]]);
        }
        if ($identity instanceof User) {
            return response()->json(['data' => ['role' => 'business-owner', 'requires_password_change' => $identity->must_change_password, 'profile' => ['id' => $identity->id, 'name' => $identity->name, 'username' => $identity->username, 'email' => $identity->email]]]);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    public function logout(Request $request, AuditLogger $auditLogger)
    {
        $identity = $request->user();
        if (Auth::guard('platform_admin')->check()) {
            Auth::guard('platform_admin')->logout();
        }
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $auditLogger->record('auth.logout', $identity, $request);

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function matchingAdmins(string $identifier)
    {
        return PlatformAdmin::query()->where(function ($query) use ($identifier): void {
            $query->whereRaw('LOWER(email) = ?', [$identifier])->orWhere('username', $identifier);
        })->get();
    }

    private function matchingOwners(string $identifier)
    {
        return User::query()->where(function ($query) use ($identifier): void {
            $query->whereRaw('LOWER(email) = ?', [$identifier])->orWhere('username', $identifier);
        })->get();
    }
}
