<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleIdentity;
use App\Services\Auth\GoogleSignIn;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Sign in or register with a Google account.
 *
 * Rules:
 *  - Google must say the email address is verified.
 *  - Staff accounts (support, admin, super admin) can never sign in this way: they move money, so they use a password.
 *  - Banned accounts are refused, exactly like the normal login.
 *  - An existing customer is matched by the email address and linked once. If that account was never verified,
 *    its old password is removed, so nobody who registered someone else's address earlier keeps access.
 */
class GoogleController extends Controller
{
    public function redirect(GoogleSignIn $google): SymfonyRedirect
    {
        abort_unless($google->enabled(), 404);

        return $google->redirect();
    }

    public function callback(Request $request, GoogleSignIn $google, AuditService $audit): RedirectResponse
    {
        abort_unless($google->enabled(), 404);

        // The visitor pressed "Cancel" at Google.
        if ($request->filled('error')) {
            return $this->fail('Google sign-in was cancelled.');
        }

        try {
            $identity = $google->user();
        } catch (Throwable $e) {
            // Only the kind of error is logged, never the request or any token.
            Log::warning('Google sign-in failed: '.class_basename($e));

            return $this->fail('We could not sign you in with Google. Please try again.');
        }

        $email = Str::lower(trim($identity->email));

        if ($identity->id === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('We could not sign you in with Google. Please try again.');
        }

        if (! $identity->verified) {
            return $this->fail('Your Google email address is not verified, so it cannot be used to sign in.');
        }

        $user = User::query()->where('google_id', $identity->id)->first()
            ?? User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user !== null) {
            return $this->signInExisting($request, $audit, $user, $identity);
        }

        return $this->register($request, $audit, $identity, $email);
    }

    private function signInExisting(Request $request, AuditService $audit, User $user, GoogleIdentity $identity): RedirectResponse
    {
        if ($user->isStaff()) {
            return $this->fail('Staff accounts must sign in with their email and password.');
        }

        if ($user->isBanned()) {
            return $this->fail('This account has been banned.');
        }

        if (filled($user->google_id) && $user->google_id !== $identity->id) {
            return $this->fail('This email address belongs to an account that is linked to a different Google account.');
        }

        $notice = null;
        $action = 'auth.google.login';

        if (blank($user->google_id)) {
            $action = 'auth.google.link';
            $wasVerified = $user->email_verified_at !== null;

            $user->forceFill([
                'google_id' => $identity->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (! $wasVerified) {
                // Nobody proved they own this address when the account was made: remove that password.
                $user->forceFill(['password' => Str::random(64), 'remember_token' => Str::random(60)]);
                $notice = 'Your account is now linked to Google. Your old password was removed for safety: use Forgot your password? to choose a new one.';
            }

            try {
                $user->save();
            } catch (UniqueConstraintViolationException) {
                return $this->fail('We could not sign you in with Google. Please try again.');
            }
        }

        return $this->loginAs($request, $audit, $user, $action, $notice);
    }

    private function register(Request $request, AuditService $audit, GoogleIdentity $identity, string $email): RedirectResponse
    {
        $name = Str::limit(trim($identity->name) !== '' ? trim($identity->name) : Str::before($email, '@'), 255, '');

        try {
            $user = new User();
            // role and status are not fillable: the database defaults (user / active) apply.
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'google_id' => $identity->id,
                'email_verified_at' => now(),
                // Nobody knows this password. The customer can choose one later with "Forgot your password?".
                'password' => Str::random(64),
            ])->save();
        } catch (UniqueConstraintViolationException) {
            return $this->fail('We could not sign you in with Google. Please try again.');
        }

        $user->refresh(); // load the role and status the database set

        return $this->loginAs($request, $audit, $user, 'auth.google.register');
    }

    private function loginAs(Request $request, AuditService $audit, User $user, string $action, ?string $notice = null): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();

        $audit->log($action, $user, actor: $user);

        $response = redirect()->intended(route('dashboard', absolute: false));

        return $notice !== null ? $response->with('status', $notice) : $response;
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}