<?php

namespace App\Providers;

use App\Services\SiteSettings;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Makes the website name chosen in Admin > Site settings the application name on every request.
 */
class SiteSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SiteSettings::class);
    }

    public function boot(): void
    {
        try {
            $this->app->make(SiteSettings::class)->applyToConfig();
        } catch (Throwable) {
            // Never stop the website (or "php artisan migrate") because of a branding problem.
        }
    }
}
