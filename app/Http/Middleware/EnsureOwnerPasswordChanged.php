<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOwnerPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Owner authentication required.'], 403);
        }
        if ($user->must_change_password) {
            return response()->json(['message' => 'Password change required before accessing the application.', 'requires_password_change' => true], 403);
        }

        return $next($request);
    }
}
