<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ProviderException;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\ProviderCategory;
use App\Models\ProviderOffer;
use App\Services\AuditService;
use App\Services\TopUp\CatalogSyncService;
use App\Services\TopUp\FazerCardsService;
use App\Services\TopUp\ProviderImportService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use RuntimeException;

class ProviderController extends Controller
{
    public function index(Request $request, FazerCardsService $api): View
    {
        if ($request->boolean('refresh')) {
            Cache::forget('fazercards:status');
        }

        $status = null;
        $error = null;
        $lowBalance = false;

        if (! $api->isConfigured()) {
            $error = 'FAZERCARDS_API_KEY is not set in .env (then run: php artisan config:clear).';
        } else {
            try {
                $status = Cache::remember('fazercards:status', 60, function () use ($api) {
                    $profile = $api->getProfile();
                    $balance = $api->getBalance();

                    return [
                        'login' => (string) ($profile['login'] ?? '?'),
                        'plan' => (string) ($profile['plan'] ?? '?'),
                        'subscription_active' => ($profile['subscriptionActive'] ?? false) === true,
                        'plan_expires_at' => isset($profile['planExpiresAt']) ? (string) $profile['planExpiresAt'] : null,
                        'balance' => $balance['balance'],
                        'currency' => $balance['currency'],
                    ];
                });

                $lowBalance = Money::cmp($status['balance'], (string) config('fazercards.low_balance', '20.00')) < 0;
            } catch (ProviderException $e) {
                $error = $e->getMessage().($e->httpStatus ? " (HTTP {$e->httpStatus})" : '');
            }
        }

        $search = trim((string) $request->query('q'));
        $like = '%'.addcslashes($search, '%_\\').'%';

        $categories = ProviderCategory::query()
            ->where('provider', 'fazercards')
            ->when($search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', $like)->orWhere('provider_category_id', 'like', $like)
            ))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $ids = $categories->pluck('provider_category_id')->all();

        $offerCounts = ProviderOffer::query()
            ->where('provider', 'fazercards')
            ->where('is_available', true)
            ->whereIn('provider_category_id', $ids)
            ->selectRaw('provider_category_id, COUNT(*) as total')
            ->groupBy('provider_category_id')
            ->pluck('total', 'provider_category_id');

        $linked = Game::query()
            ->whereIn('provider_category_id', $ids)
            ->get(['id', 'name', 'provider_category_id'])
            ->keyBy('provider_category_id');

        return view('admin.provider.index', [
            'status' => $status,
            'error' => $error,
            'lowBalance' => $lowBalance,
            'lastSync' => Cache::get('fazercards:last_sync'),
            'categories' => $categories,
            'offerCounts' => $offerCounts,
            'linked' => $linked,
            'linkableGames' => Game::query()->whereNull('provider_category_id')->orderBy('name')->get(['id', 'name']),
            'search' => $search,
        ]);
    }

    public function sync(CatalogSyncService $sync): RedirectResponse
    {
        @set_time_limit(180);

        try {
            $summary = $sync->sync();
        } catch (ProviderException $e) {
            return back()->with('error', 'Sync failed: '.$e->getMessage());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Cache::forget('fazercards:status');

        $message = sprintf(
            'Synced %d categories, %d offers, %d product cost change(s).',
            $summary['categories'],
            $summary['offers'],
            $summary['price_changes']
        );

        $redirect = back()->with('status', $message);

        return $summary['errors'] === []
            ? $redirect
            : $redirect->with('error', implode(' ', array_slice($summary['errors'], 0, 3)));
    }

    public function createGame(
        ProviderCategory $category,
        CatalogSyncService $sync,
        ProviderImportService $import,
        AuditService $audit,
    ): RedirectResponse {
        if (Game::query()->where('provider_category_id', $category->provider_category_id)->exists()) {
            return back()->with('error', 'A game is already linked to this category.');
        }

        $game = new Game([
            'name' => $category->name,
            'status' => 'inactive',
            'sort_order' => 0,
            'provider_category_id' => $category->provider_category_id,
        ]);
        $game->slug = Game::uniqueSlug($category->name);
        $game->save();

        $audit->log('game.created', $game, [], [
            'name' => $game->name,
            'slug' => $game->slug,
            'source' => 'provider',
            'provider_category_id' => $category->provider_category_id,
        ]);

        $notes = [];

        try {
            $sync->syncCategoryNow($category->provider_category_id);
            $result = $import->importFields($game);
            $notes[] = "{$result['created']} player field(s) imported";
        } catch (ProviderException|RuntimeException|DomainException $e) {
            $notes[] = 'Details could not be imported yet ('.$e->getMessage().')';
        }

        return redirect()
            ->route('admin.games.edit', $game)
            ->with('status', 'Game created as inactive: '.implode('; ', $notes).'. Next: import the offers, check the prices, then enable the game.');
    }

    public function linkGame(
        Request $request,
        ProviderCategory $category,
        CatalogSyncService $sync,
        AuditService $audit,
    ): RedirectResponse {
        $data = $request->validate(['game_id' => ['required', 'integer', 'exists:games,id']]);

        $game = Game::query()->findOrFail((int) $data['game_id']);

        if (filled($game->provider_category_id)) {
            return back()->with('error', 'That game is already linked to a provider category.');
        }

        if (Game::query()->where('provider_category_id', $category->provider_category_id)->exists()) {
            return back()->with('error', 'Another game already uses this category.');
        }

        $game->provider_category_id = $category->provider_category_id;
        $game->save();

        $audit->log('game.provider_linked', $game, [], ['provider_category_id' => $category->provider_category_id]);

        $note = 'Offers refreshed.';

        try {
            $sync->syncCategoryNow($category->provider_category_id);
        } catch (ProviderException|RuntimeException $e) {
            $note = 'Offers could not be refreshed yet ('.$e->getMessage().').';
        }

        return redirect()
            ->route('admin.games.edit', $game)
            ->with('status', "Linked {$game->name} to {$category->name}. {$note}");
    }
}