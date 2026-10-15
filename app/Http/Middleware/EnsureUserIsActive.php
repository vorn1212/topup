<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply to every route that deposits, purchases or creates orders.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isActive()) {
            return $next($request);
        }

        if ($user->isBanned()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? ApiResponse::error('Your account has been banned.', [], 403)
                : redirect()->route('login')->withErrors(['email' => 'Your account has been banned.']);
        }

        $message = 'Your account is suspended. You cannot deposit, purchase or create orders. Please contact support.';

        return $request->expectsJson()
            ? ApiResponse::error($message, [], 403)
            : redirect()->route('dashboard')->with('error', $message);
    }
}