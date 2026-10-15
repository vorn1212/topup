<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /locale: remember the language the visitor picked (session, cookie, and the account when signed in).
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(SetLocale::LOCALES))],
        ]);

        $locale = $data['locale'];

        $request->session()->put('locale', $locale);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return redirect()
            ->to($this->returnUrl($request))
            ->withCookie(cookie()->forever('locale', $locale));
    }

    /** Back to the page the visitor was on, but never to another website. */
    private function returnUrl(Request $request): string
    {
        $previous = url()->previous();
        $host = parse_url($previous, PHP_URL_HOST);

        return $host === $request->getHost() ? $previous : route('home');
    }
}