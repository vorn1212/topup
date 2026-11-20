<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a signed-in session once its tab or browser has been closed.
 *
 * Pages carry a tiny script (public/kt-session.js) that keeps a marker in sessionStorage, which the
 * browser drops when the tab closes. A signed-in page that opens without the marker is a new tab or a
 * restarted browser, so the script calls POST /_session/end and reloads. Closing the whole browser is
 * also covered by SESSION_EXPIRE_ON_CLOSE.
 */
class LogoutWhenBrowserCloses
{
    /** POST /_session/end */
    public function end(Request $request)
    {
        if (Auth::check() && ! self::isGuestAccount()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            return $response;
        }

        $html = $response->getContent();

        if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
            || ! is_string($html) || ! str_contains($html, '</body>')) {
            return $response;
        }

        $signedIn = Auth::check() && ! self::isGuestAccount();
        $tag = '<script src="/kt-session.js"'.($signedIn ? ' data-signed-in="1"' : '').' defer></script>';

        // setContent() would replace the original View, which tests (and anything else reading
        // $response->original / viewData()) still need, so put it back afterwards.
        $original = property_exists($response, 'original') ? $response->original : null;

        $response->setContent(preg_replace('~</body>(?!.*</body>)~is', $tag.'</body>', $html, 1));

        if ($original !== null) {
            $response->original = $original;
        }

        return $response;
    }

    private static function isGuestAccount(): bool
    {
        return str_ends_with((string) Auth::user()?->email, '@guest.invalid');
    }
}
