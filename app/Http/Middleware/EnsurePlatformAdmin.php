<?php

namespace App\Http\Middleware;

use App\Models\PlatformAdmin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof PlatformAdmin) {
            return response()->json(['message' => 'Platform administrator access required.'], 403);
        }

        if (! $user->is_active) {
            auth('platform_admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Your administrator account is inactive.'], 403);
        }

        return $next($request);
    }
}
