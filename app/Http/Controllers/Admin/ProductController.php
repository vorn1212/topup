<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CatalogStatus;
use App\Enums\MarkupType;
use App\Exceptions\PriceBelowCostException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Game;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Services\AuditService;
use App\Services\Catalog\ImageUploader;
use App\Services\Pricing\PricingService;
use App\Support\NumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $gameId = (int) $request->query('game_id');
        $status = CatalogStatus::tryFrom((string) $request->query('status'));

        $products = Product::query()
            ->with('game')
            ->when($gameId > 0, fn ($q) => $q->where('game_id', $gameId))
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->when($search !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%")
            ))
            ->orderBy('game_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'games' => Game::query()->orderBy('name')->get(['id', 'name']),
            'search' => $search,
            'gameId' => $gameId,
            'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.products.form', [
            'product' => null,
            'games' => Game::query()->orderBy('name')->get(['id', 'name']),
            'selectedGameId' => (int) $request->query('game_id'),
            'histories' => collect(),
        ]);
    }

    public function store(CreateProductRequest $request, PricingService $pricing, AuditService $audit): RedirectResponse
    {
        $data = $request->validated();
        $game = Game::query()->findOrFail((int) $data['game_id']);

        try {
            $product = DB::transaction(function () use ($data, $game, $request, $pricing) {
                $product = new Product([
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'status' => $data['status'],
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                ]);
                $product->game_id = $game->id;
                $product->sku = filled($data['sku'] ?? null) ? $data['sku'] : $this->generateSku($game);
                $product->provider_offer_id = filled($data['provider_offer_id'] ?? null) ? $data['provider_offer_id'] : null;
                $product->is_available = $request->boolean('is_available', true);
                $product->currency = (string) config('topup.currency', 'USD');
                $product->save();

                // Price columns start at zero and are set only by PricingService (with history).
                $pricing->update(
                    $product,
                    $data['provider_cost'],
                    MarkupType::from($data['markup_type']),
                    $data['markup_value'],
                    $request->user(),
                    'Product created',
                    $request->boolean('confirm_loss'),
                );

                return $product;
            });
        } catch (PriceBelowCostException|InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['markup_value' => $e->getMessage()]);
        }

        $audit->log('product.created', $product, [], [
            'game_id' => $game->id,
            'name' => $product->name,
            'sku' => $product->sku,
        ]);

        return redirect()->route('admin.products.edit', $product)->with('status', 'Product created.');
    }

    public function edit(Product $product): View
    {
        $product->load('game');

        return view('admin.products.form', [
            'product' => $product,
            'games' => collect(),
            'selectedGameId' => $product->game_id,
            'histories' => ProductPriceHistory::query()
                ->with('admin')
                ->where('product_id', $product->id)
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product, AuditService $audit, ImageUploader $uploader): RedirectResponse
    {
        $data = $request->validated();
        $old = [
            'name' => $product->name,
            'status' => $product->status->value,
            'is_available' => $product->is_available,
            'provider_offer_id' => $product->provider_offer_id,
        ];

        $product->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        $product->provider_offer_id = filled($data['provider_offer_id'] ?? null) ? $data['provider_offer_id'] : null;
        $product->is_available = $request->boolean('is_available');

        // Package icon: upload a new one or remove the current one.
        $oldIcon = $product->icon;
        $newIcon = null;

        try {
            if ($request->hasFile('icon')) {
                try {
                    $newIcon = $uploader->store($request->file('icon'), 'games/product-icons', 256, 256, 'png');
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['icon' => $e->getMessage()]);
                }
                $product->icon = $newIcon;
            } elseif ($request->boolean('remove_icon')) {
                $product->icon = null;
            }

            $product->save();
        } catch (Throwable $e) {
            if ($newIcon !== null) {
                $uploader->delete($newIcon);
            }

            throw $e;
        }

        if ($oldIcon && $oldIcon !== $product->icon) {
            $uploader->delete($oldIcon);
            $audit->log('product.icon_changed', $product, ['icon' => $oldIcon], ['icon' => $product->icon]);
        } elseif ($newIcon !== null) {
            $audit->log('product.icon_changed', $product, ['icon' => null], ['icon' => $product->icon]);
        }

        $audit->log('product.updated', $product, $old, [
            'name' => $product->name,
            'status' => $product->status->value,
            'is_available' => $product->is_available,
            'provider_offer_id' => $product->provider_offer_id,
        ]);

        return redirect()->route('admin.products.edit', $product)->with('status', 'Product saved.');
    }

    public function toggle(Product $product, AuditService $audit): RedirectResponse
    {
        $old = $product->status;
        $product->status = $old === CatalogStatus::Active ? CatalogStatus::Inactive : CatalogStatus::Active;
        $product->save();

        $audit->log('product.toggled', $product, ['status' => $old->value], ['status' => $product->status->value]);

        return back()->with('status', "{$product->name} is now {$product->status->value}.");
    }

    public function history(Product $product): View
    {
        return view('admin.products.history', [
            'product' => $product,
            'histories' => ProductPriceHistory::query()
                ->with('admin')
                ->where('product_id', $product->id)
                ->latest('id')
                ->paginate(25),
        ]);
    }

    private function generateSku(Game $game): string
    {
        $prefix = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $game->slug), 0, 8)) ?: 'PRD';

        do {
            $sku = $prefix.'-'.NumberGenerator::random(6);
        } while (Product::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
