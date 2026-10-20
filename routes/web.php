<?php

use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Site\GameController as SiteGameController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\PlayerValidationController;
use App\Http\Controllers\Site\SitemapController;
use App\Http\Controllers\User\DashboardController;
use App\Http\Controllers\User\DepositController;
use App\Http\Controllers\User\NotificationController;
use App\Http\Controllers\User\OrderController;
use App\Http\Controllers\User\WalletController;
use App\Http\Controllers\Webhooks\FazerCardsWebhookController;
use App\Http\Middleware\SetLocale;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleController;

// Everything a customer sees runs through SetLocale (English / Khmer). The webhook below does not:
// it has no session and no cookies.
Route::middleware(SetLocale::class)->group(function () {
    // ---- Public site (SEO pages) ----
    Route::get('/', HomeController::class)->name('home');

    Route::get('/games', [SiteGameController::class, 'index'])->name('games.index');
    Route::get('/games/{slug}', [SiteGameController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')->name('games.show');
    Route::get('/games/{slug}/top-up', [SiteGameController::class, 'topUp'])
        ->where('slug', '[a-z0-9\-]+')->name('games.topup');

    Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
    Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

    Route::view('/contact', 'site.contact')->name('contact');

        // ---- Sign in with Google (guests only; the pages are hidden until Google is set up in .env) ----
    Route::middleware(['guest', 'throttle:20,1'])->group(function () {
        Route::get('/auth/google', [GoogleController::class, 'redirect'])->name('auth.google');
        Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
    });

    // ---- Signed-in area ----
    Route::middleware('auth')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');

        Route::get('/wallet/deposit', [DepositController::class, 'create'])->name('wallet.deposit.create');
        Route::post('/wallet/deposit', [DepositController::class, 'store'])
            ->middleware(['active', 'throttle:6,1'])
            ->name('wallet.deposit.store');

        Route::get('/wallet/deposit/{depositNumber}', [DepositController::class, 'show'])
            ->name('wallet.deposit.show');
        Route::get('/wallet/deposit/{depositNumber}/status', [DepositController::class, 'status'])
            ->middleware('throttle:90,1')
            ->name('wallet.deposit.status');
        Route::get('/wallet/deposit/{depositNumber}/qr', [DepositController::class, 'qr'])
            ->middleware('throttle:90,1')
            ->name('wallet.deposit.qr');

        // Player ID check (calls the provider, so: login required, active account, throttled).
        Route::post('/games/{slug}/validate-player', PlayerValidationController::class)
            ->where('slug', '[a-z0-9\-]+')
            ->middleware(['active', 'throttle:20,1'])
            ->name('games.validate-player');

        // Orders. Placing one needs an active account; viewing your own history never does.
        Route::post('/orders', [OrderController::class, 'store'])
            ->middleware(['active', 'throttle:10,1'])
            ->name('orders.store');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/order/{orderNumber}', [OrderController::class, 'show'])
            ->where('orderNumber', 'ORD-\d{8}-[A-Z0-9]{6}')
            ->name('orders.show');
        Route::get('/order/{orderNumber}/status', [OrderController::class, 'status'])
            ->where('orderNumber', 'ORD-\d{8}-[A-Z0-9]{6}')
            ->middleware('throttle:90,1')
            ->name('orders.status');

        // Notifications (deposit received, order delivered or refunded).
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
            ->middleware('throttle:20,1')
            ->name('notifications.read-all');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        // Account deletion intentionally removed: users hold wallets and order history.
    });

    // ---- Language switch ----
    Route::post('/locale', [LocaleController::class, 'update'])->middleware('throttle:30,1')->name('locale.update');

    require __DIR__.'/auth.php';
});

// ---- Provider webhooks ----
// Called by the provider's servers: no session, no cookies, no CSRF token. The request is trusted only
// after its HMAC signature is verified, and even then it only triggers a status check.
Route::post('/webhooks/fazercards', FazerCardsWebhookController::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->middleware('throttle:120,1')
    ->name('webhooks.fazercards');
