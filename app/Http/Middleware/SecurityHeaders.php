<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * A policy that cannot break the pages (no script or style rules), but closes the common doors:
     * the site cannot be put in a frame by another site, an injected <base> tag or <object> is ignored,
     * and forms can only be sent back to this site.
     */
    public const CSP = "base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', self::CSP);

        // Pages of a signed-in user (wallet, orders, admin) must not be kept by the browser or a proxy,
        // so the Back button after logging out never shows them again.
        if ($request->hasSession()
            && $request->user()
            && ! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}