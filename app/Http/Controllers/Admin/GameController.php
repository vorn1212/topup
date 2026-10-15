<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CatalogStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateGameRequest;
use App\Http\Requests\UpdateGameRequest;
use App\Models\Game;
use App\Services\AuditService;
use App\Services\Catalog\ImageUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;
use App\Exceptions\ProviderException;
use App\Models\Product;
use App\Models\ProviderCategory;
use App\Models\ProviderOffer;
use App\Services\TopUp\FazerCardsService;
use Illuminate\Support\Facades\Cache;

class GameController extends Controller
{
    /** [directory, max width, max height, output format] */
    private const LOGO = ['games/logos', 512, 512, 'png'];

    private const BANNER = ['games/banners', 1600, 600, 'jpeg'];

    private const NON_COLUMNS = ['logo', 'banner', 'remove_logo', 'remove_banner'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $games = Game::query()
            ->withCount('products')
            ->when($search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")
            ))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.games.index', compact('games', 'search'));
    }

    public function create(): View
    {
        return view('admin.games.form', ['game' => null]);
    }

    public function store(CreateGameRequest $request, ImageUploader $uploader, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $storedFiles = [];

        try {
            $game = new Game(Arr::except($data, self::NON_COLUMNS));
            $game->slug = ($data['slug'] ?? null) ?: Game::uniqueSlug($data['name']);
            $game->sort_order = (int) ($data['sort_order'] ?? 0);

            if ($request->hasFile('logo')) {
                $path = $this->storeImage($uploader, $request->file('logo'), self::LOGO, 'logo');
                $storedFiles[] = $path;
                $game->logo = $path;
            }

            if ($request->hasFile('banner')) {
                $path = $this->storeImage($uploader, $request->file('banner'), self::BANNER, 'banner');
                $storedFiles[] = $path;
                $game->banner = $path;
            }

            $game->save();
        } catch (Throwable $e) {
            foreach ($storedFiles as $path) {
                $uploader->delete($path);
            }

            throw $e;
        }

        $audit->log('game.created', $game, [], [
            'name' => $game->name,
            'slug' => $game->slug,
            'status' => $game->status->value,
        ]);

        return redirect()
            ->route('admin.games.edit', $game)
            ->with('status', 'Game created. Add the player fields customers must fill in below.');
    }

    public function edit(Game $game, FazerCardsService $provider): View
    {
        $game->load('fields');

        $mirror = null;
        $offerCount = 0;
        $importedCount = 0;

        if (filled($game->provider_category_id)) {
            $mirror = ProviderCategory::query()
                ->where('provider', 'fazercards')
                ->where('provider_category_id', $game->provider_category_id)
                ->first();

            $offerCount = ProviderOffer::query()
                ->where('provider', 'fazercards')
                ->where('provider_category_id', $game->provider_category_id)
                ->where('is_available', true)
                ->count();

            $importedCount = Product::query()
                ->where('game_id', $game->id)
                ->whereNotNull('provider_offer_id')
                ->count();
        }

        return view('admin.games.form', [
            'game' => $game,
            'validatable' => $this->validatableGames($provider),
            'mirror' => $mirror,
            'offerCount' => $offerCount,
            'importedCount' => $importedCount,
        ]);
    }

    /**
     * The provider's list of games that support player checks (cached for an hour). Null if unavailable.
     *
     * @return list<array{category_id:string,name:string,fields:list<array<string,string>>}>|null
     */
    private function validatableGames(FazerCardsService $provider): ?array
    {
        if (! $provider->isConfigured()) {
            return null;
        }

        try {
            return Cache::remember('fazercards:validatable', 3600, fn () => $provider->getValidatableGames());
        } catch (ProviderException) {
            return null;
        }
    }

    public function update(UpdateGameRequest $request, Game $game, ImageUploader $uploader, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $old = [
            'name' => $game->name,
            'slug' => $game->slug,
            'status' => $game->status->value,
            'sort_order' => $game->sort_order,
        ];
        $oldLogo = $game->logo;
        $oldBanner = $game->banner;
        $storedFiles = [];

        try {
            $game->fill(Arr::except($data, self::NON_COLUMNS));
            $game->slug = ($data['slug'] ?? null) ?: $game->slug;
            $game->sort_order = (int) ($data['sort_order'] ?? 0);

            if ($request->hasFile('logo')) {
                $path = $this->storeImage($uploader, $request->file('logo'), self::LOGO, 'logo');
                $storedFiles[] = $path;
                $game->logo = $path;
            } elseif ($request->boolean('remove_logo')) {
                $game->logo = null;
            }

            if ($request->hasFile('banner')) {
                $path = $this->storeImage($uploader, $request->file('banner'), self::BANNER, 'banner');
                $storedFiles[] = $path;
                $game->banner = $path;
            } elseif ($request->boolean('remove_banner')) {
                $game->banner = null;
            }

            $game->save();
        } catch (Throwable $e) {
            foreach ($storedFiles as $path) {
                $uploader->delete($path);
            }

            throw $e;
        }

        if ($oldLogo && $oldLogo !== $game->logo) {
            $uploader->delete($oldLogo);
        }
        if ($oldBanner && $oldBanner !== $game->banner) {
            $uploader->delete($oldBanner);
        }

        $audit->log('game.updated', $game, $old, [
            'name' => $game->name,
            'slug' => $game->slug,
            'status' => $game->status->value,
            'sort_order' => $game->sort_order,
        ]);

        return redirect()->route('admin.games.edit', $game)->with('status', 'Game saved.');
    }

    public function toggle(Game $game, AuditService $audit): RedirectResponse
    {
        $old = $game->status;
        $game->status = $old === CatalogStatus::Active ? CatalogStatus::Inactive : CatalogStatus::Active;
        $game->save();

        $audit->log('game.toggled', $game, ['status' => $old->value], ['status' => $game->status->value]);

        return back()->with('status', "{$game->name} is now {$game->status->value}.");
    }

    public function destroy(Game $game, ImageUploader $uploader, AuditService $audit): RedirectResponse
    {
        if ($game->products()->exists()) {
            return back()->with('error', 'This game has products and price history. Disable it instead of deleting it.');
        }

        $audit->log('game.deleted', $game, ['name' => $game->name, 'slug' => $game->slug]);

        $logo = $game->logo;
        $banner = $game->banner;
        $game->delete(); // game_fields are removed by the foreign key cascade

        $uploader->delete($logo);
        $uploader->delete($banner);

        return redirect()->route('admin.games.index')->with('status', 'Game deleted.');
    }

    private function storeImage(ImageUploader $uploader, UploadedFile $file, array $spec, string $field): string
    {
        try {
            return $uploader->store($file, ...$spec);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }
}