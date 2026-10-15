<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MarkupType;
use App\Exceptions\PriceBelowCostException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustPriceRequest;
use App\Http\Requests\UpdatePriceRequest;
use App\Models\Product;
use App\Services\Pricing\PricingService;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

class PriceController extends Controller
{
    public function update(UpdatePriceRequest $request, Product $product, PricingService $pricing): RedirectResponse
    {
        $data = $request->validated();

        try {
            $pricing->update(
                $product,
                $data['provider_cost'],
                MarkupType::from($data['markup_type']),
                $data['markup_value'],
                $request->user(),
                $data['reason'],
                $request->boolean('confirm_loss'),
            );
        } catch (PriceBelowCostException|InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['price' => $e->getMessage()]);
        }

        return redirect()->route('admin.products.edit', $product)->with('status', 'Price updated.');
    }

    public function adjust(AdjustPriceRequest $request, Product $product, PricingService $pricing): RedirectResponse
    {
        try {
            $updated = $pricing->adjust(
                $product,
                (string) $request->validated('delta'),
                $request->user(),
                $request->validated('reason'),
            );
        } catch (PriceBelowCostException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$updated->name}: price is now ".\App\Support\Money::display((string) $updated->selling_price).'.');
    }
}