<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a signed-in session once its tab or browser has been closed.
 *
 * Every page sets a small marker in sessionStorage, which the browser drops when the tab closes.
 * A signed-in page that opens without the marker is a new tab or a restarted browser, so the
 * script calls POST /_session/end and reloads. (Closing the whole browser is also covered by
 * SESSION_EXPIRE_ON_CLOSE, which makes the session cookie temporary.)
 */
class LogoutWhenBrowserCloses
{
    public const END_PATH = '_session/end';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->path() === self::END_PATH) {
            if (Auth::check() && ! $this->isGuestAccount()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->noContent();
        }

        $response = $next($request);

        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            return $response;
        }

        $type = (string) $response->headers->get('Content-Type');
        $html = $response->getContent();

        if (! str_contains($type, 'text/html') || ! is_string($html) || ! str_contains($html, '</body>')) {
            return $response;
        }

        $response->setContent(preg_replace('~</body>(?!.*</body>)~is', $this->script().'</body>', $html, 1));

        return $response;
    }

    private function isGuestAccount(): bool
    {
        return str_ends_with((string) Auth::user()?->email, '@guest.invalid');
    }

    private function script(): string
    {
        $check = Auth::check() && ! $this->isGuestAccount();
        $token = e(csrf_token());
        $url = e(url(self::END_PATH));

        if (! $check) {
            return "<script>try{sessionStorage.setItem('kt_tab','1')}catch(e){}</script>";
        }

        return "<script>(function(){try{if(sessionStorage.getItem('kt_tab')==='1')return;}catch(e){return;}"
            ."fetch('{$url}',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-TOKEN':'{$token}','X-Requested-With':'XMLHttpRequest'}})"
            .".finally(function(){try{sessionStorage.setItem('kt_tab','1')}catch(e){}location.reload();});})();</script>";
    }
}
