<?php

namespace App\Services\Auth;

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The only place that talks to Laravel Socialite. Everything else (and every test) uses this small class,
 * so the sign-in rules can be tested without calling Google.
 */
class GoogleSignIn
{
    /** True when the package is installed and the client id and secret are set in .env. */
    public function enabled(): bool
    {
        return class_exists(GoogleProvider::class)
            && filled(config('google.client_id'))
            && filled(config('google.client_secret'));
    }

    /** Send the visitor to Google's sign-in page. */
    public function redirect(): RedirectResponse
    {
        return $this->provider()->redirect();
    }

    /**
     * Read the answer Google sent back. Throws when the state check or the code exchange fails.
     */
    public function user(): GoogleIdentity
    {
        $google = $this->provider()->user();

        $raw = is_array($google->user ?? null) ? $google->user : [];

        // Google reports whether it has verified the email address. Newer and older userinfo versions
        // name the field differently, so both are read.
        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return new GoogleIdentity(
            id: (string) $google->getId(),
            email: (string) $google->getEmail(),
            name: (string) $google->getName(),
            verified: $verified === true || $verified === 'true' || $verified === 1,
        );
    }

    private function provider(): GoogleProvider
    {
        return Socialite::buildProvider(GoogleProvider::class, [
            'client_id' => config('google.client_id'),
            'client_secret' => config('google.client_secret'),
            'redirect' => config('google.redirect') ?: route('auth.google.callback'),
        ]);
    }
}