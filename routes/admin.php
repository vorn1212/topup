<?php

use App\Http\Controllers\Admin\AttentionController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BulkPriceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DepositController;
use App\Http\Controllers\Admin\GameController;
use App\Http\Controllers\Admin\GameFieldController;
use App\Http\Controllers\Admin\GameProviderController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PriceController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

// Prefix "admin", name "admin.", middleware auth + role are applied in bootstrap/app.php.

Route::get('/', DashboardController::class)
    ->middleware('can:dashboard.view')
    ->name('dashboard');

Route::middleware('can:games.manage')->group(function () {
    Route::resource('games', GameController::class)->except(['show']);
    Route::patch('games/{game}/toggle', [GameController::class, 'toggle'])->name('games.toggle');

    Route::post('games/{game}/fields', [GameFieldController::class, 'store'])->name('games.fields.store');
    Route::patch('games/{game}/fields/{field}', [GameFieldController::class, 'update'])->name('games.fields.update');
    Route::delete('games/{game}/fields/{field}', [GameFieldController::class, 'destroy'])->name('games.fields.destroy');
});

Route::middleware('can:products.manage')->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('products', [ProductController::class, 'store'])->name('products.store');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::patch('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::patch('products/{product}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
    Route::get('products/{product}/history', [ProductController::class, 'history'])->name('products.history');
});

Route::middleware('can:pricing.manage')->group(function () {
    Route::patch('products/{product}/price', [PriceController::class, 'update'])->name('products.price.update');
    Route::post('products/{product}/price/adjust', [PriceController::class, 'adjust'])->name('products.price.adjust');

    Route::post('products/bulk-price/preview', [BulkPriceController::class, 'preview'])->name('products.bulk.preview');
    Route::post('products/bulk-price/apply', [BulkPriceController::class, 'apply'])->name('products.bulk.apply');
});

Route::middleware('can:provider.manage')->group(function () {
    Route::get('provider', [ProviderController::class, 'index'])->name('provider.index');
    Route::post('provider/sync', [ProviderController::class, 'sync'])->middleware('throttle:6,1')->name('provider.sync');
    Route::post('provider/categories/{category}/create-game', [ProviderController::class, 'createGame'])->name('provider.categories.create-game');
    Route::post('provider/categories/{category}/link', [ProviderController::class, 'linkGame'])->name('provider.categories.link');

    Route::post('games/{game}/provider/refresh', [GameProviderController::class, 'refresh'])->name('games.provider.refresh');
    Route::post('games/{game}/provider/fields', [GameProviderController::class, 'importFields'])->name('games.provider.fields');
    Route::post('games/{game}/provider/offers', [GameProviderController::class, 'importOffers'])->name('games.provider.offers');
});

// Orders that need a human: review, stuck, provider balance, webhook log.
Route::middleware('can:orders.view')->group(function () {
    Route::get('attention', [AttentionController::class, 'index'])->name('attention.index');
    Route::get('attention/orders/{providerOrder}', [AttentionController::class, 'show'])->name('attention.orders.show');
});

Route::middleware('can:orders.manage')->group(function () {
    Route::post('attention/balance', [AttentionController::class, 'refreshBalance'])->middleware('throttle:6,1')->name('attention.balance');

    Route::middleware('throttle:30,1')->group(function () {
        Route::post('attention/orders/{providerOrder}/recheck', [AttentionController::class, 'recheck'])->name('attention.orders.recheck');
        Route::post('attention/orders/{providerOrder}/resend', [AttentionController::class, 'resend'])->name('attention.orders.resend');
        Route::post('attention/orders/{providerOrder}/complete', [AttentionController::class, 'complete'])->name('attention.orders.complete');
        Route::post('attention/orders/{providerOrder}/refund', [AttentionController::class, 'refund'])->name('attention.orders.refund');
    });
});

// ---- Users ----
Route::middleware('can:users.view')->group(function () {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
});

Route::middleware(['can:users.manage', 'throttle:30,1'])->group(function () {
    Route::post('users/{user}/status', [UserController::class, 'status'])->name('users.status');
    Route::post('users/{user}/freeze', [UserController::class, 'freeze'])->name('users.freeze');
});

Route::post('users/{user}/role', [UserController::class, 'role'])
    ->middleware(['can:roles.manage', 'throttle:30,1'])
    ->name('users.role');

Route::post('users/{user}/adjust', [UserController::class, 'adjust'])
    ->middleware(['can:wallet.adjust', 'throttle:20,1'])
    ->name('users.adjust');

// ---- Orders, deposits, ledger ----
Route::middleware('can:orders.view')->group(function () {
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
});

Route::post('orders/{order}/notes', [OrderController::class, 'addNote'])
    ->middleware(['can:orders.manage', 'throttle:30,1'])
    ->name('orders.notes.store');

Route::get('deposits', [DepositController::class, 'index'])->middleware('can:deposits.view')->name('deposits.index');
Route::get('transactions', [TransactionController::class, 'index'])->middleware('can:transactions.view')->name('transactions.index');

// ---- Reports and audit log (read-only) ----
Route::middleware('can:reports.view')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export/{type}', [ReportController::class, 'export'])
        ->whereIn('type', ReportController::EXPORTS)
        ->middleware('throttle:10,1')
        ->name('reports.export');
});

Route::middleware('can:audit.view')->group(function () {
    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('audit/{auditLog}', [AuditLogController::class, 'show'])->whereNumber('auditLog')->name('audit.show');
});

// ---- System health (super admin only: settings.manage) ----
Route::get('health', [HealthController::class, 'index'])->middleware('can:settings.manage')->name('health.index');

// ---- Website look: name, logo, favicon, colours (super admin only: settings.manage) ----
Route::middleware('can:settings.manage')->group(function () {
    Route::get('settings', [SiteSettingsController::class, 'edit'])->name('settings.edit');
    Route::post('settings', [SiteSettingsController::class, 'update'])->middleware('throttle:20,1')->name('settings.update');
    Route::post('settings/reset', [SiteSettingsController::class, 'reset'])->middleware('throttle:20,1')->name('settings.reset');
});
