<?php

namespace App\Providers;

use App\Contracts\PayWaySessionRunner;
use App\Contracts\TopUpProvider;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Payment\NodeSessionRunner;
use App\Services\TopUp\FakeTopUpProvider;
use App\Services\TopUp\FazerCardsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PayWaySessionRunner::class, NodeSessionRunner::class);

        // The fake provider can NEVER be used outside local/testing, whatever .env says.
        $this->app->bind(TopUpProvider::class, function ($app) {
            return config('fazercards.fake') && $app->environment(['local', 'testing'])
                ? $app->make(FakeTopUpProvider::class)
                : $app->make(FazerCardsService::class);
        });
    }

    public function boot(): void
    {
        // Catch N+1 queries during development.
        Model::preventLazyLoading(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(8);

            return $this->app->isProduction()
                ? $rule->letters()->mixedCase()->numbers()->uncompromised()
                : $rule;
        });

        // Super admins pass every gate, but only while their account is active.
        Gate::before(function (User $user, string $ability) {
            if ($user->isActive() && $user->role === UserRole::SuperAdmin) {
                return true;
            }

            return null;
        });

        foreach (config('permissions.all', []) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }

        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });
    }
}