<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Illuminate\Support\Str;

class GameController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $games = Game::query()
            ->active()
            ->withStartingPrice()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        // Search results are not indexed; later pages get their own canonical URL.
        $canonical = ($search === '' && $games->currentPage() > 1)
            ? $games->url($games->currentPage())
            : route('games.index');

        return view('site.games.index', compact('games', 'search', 'canonical'));
    }

    public function show(string $slug): View
    {
        $game = $this->activeGame($slug);

        return view('site.games.show', [
            'game' => $game,
            'products' => $game->products,
        ]);
    }

        public function topUp(Request $request, string $slug, WalletService $wallets): View
    {
        $game = $this->activeGame($slug);
        $user = $request->user();

        // After logging in, bring the visitor back to this exact page.
        if (! $user) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        $products = $game->products;
        // After a failed order the form comes back with the same package selected.
        $requested = (int) ($request->old('product_id') ?: $request->query('product'));
        $selected = $products->first(fn ($p) => $p->id === $requested && $p->is_available);

        return view('site.games.topup', [
            'game' => $game,
            'products' => $products,
            'fields' => $game->fields,
            'selectedId' => $selected?->id,
            'user' => $user,
            'balance' => $user ? $wallets->getBalance($user) : null,
            'orderingReady' => Route::has('orders.store'),
            'validationReady' => filled($game->validation_category_id) && filled(config('fazercards.api_key')),
            'validateUrl' => route('games.validate-player', $game->slug),
            // One token per page view: a double click or the back button cannot charge twice.
            'requestToken' => (string) Str::uuid(),
        ]);
    }

    private function activeGame(string $slug): Game
    {
        return Game::query()
            ->active()
            ->where('slug', $slug)
            ->with([
                'fields',
                'products' => fn ($q) => $q->where('status', 'active'),
            ])
            ->firstOrFail();
    }
}