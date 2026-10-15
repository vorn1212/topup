<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the display language of the customer side of the site.
 *
 * Order: the language saved on the signed-in account, then the one chosen in this browser session,
 * then the browser cookie (for visitors who are not signed in), then the site default.
 * Only the values in LOCALES are ever accepted, so nothing the browser sends can become a file path or markup.
 */
class SetLocale
{
    /** code => name written in that language (shown on the switcher) */
    public const LOCALES = [
        'en' => 'English',
        'km' => 'ខ្មែរ',
    ];

    public static function supported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::LOCALES);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $candidates = [
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->cookie('locale'),
        ];

        foreach ($candidates as $candidate) {
            if (self::supported($candidate)) {
                return $candidate;
            }
        }

        $default = config('app.locale');

        return self::supported($default) ? $default : 'en';
    }
}