<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAdmin;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, AuditLogger $auditLogger)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
        ]);
        $key = 'admin-login:'.strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $auditLogger->record('admin.login.rate_limited', request: $request, metadata: ['email' => $credentials['email']]);
            throw ValidationException::withMessages(['email' => 'Too many login attempts. Please try again later.']);
        }

        $admin = PlatformAdmin::where('email', $credentials['email'])->first();
        if (! $admin || ! Auth::guard('platform_admin')->validate($credentials)) {
            RateLimiter::hit($key, 60);
            $auditLogger->record('admin.login.failed', request: $request, metadata: ['email' => $credentials['email']]);
            throw ValidationException::withMessages(['email' => 'The provided credentials are invalid.']);
        }

        if (! $admin->is_active) {
            RateLimiter::hit($key, 60);
            $auditLogger->record('admin.login.inactive', $admin, $request);
            throw ValidationException::withMessages(['email' => 'The administrator account is inactive.']);
        }

        RateLimiter::clear($key);
        Auth::guard('platform_admin')->login($admin);
        $request->session()->regenerate();
        $admin->forceFill(['last_login_at' => now()])->save();
        $auditLogger->record('admin.login.succeeded', $admin, $request);

        return response()->json(['data' => $this->profile($admin)]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function logout(Request $request, AuditLogger $auditLogger)
    {
        $admin = $request->user();
        Auth::guard('platform_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $auditLogger->record('admin.logout', $admin, $request);

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function profile(PlatformAdmin $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'is_active' => $admin->is_active];
    }
}
