<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BulkOperation;
use App\Exceptions\PriceBelowCostException;
use App\Exceptions\StalePreviewException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkPriceUpdateRequest;
use App\Http\Requests\BulkPricePreviewRequest;
use App\Services\Pricing\BulkPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

class BulkPriceController extends Controller
{
    public function preview(BulkPricePreviewRequest $request, BulkPricingService $bulk): View|RedirectResponse
    {
        $operation = BulkOperation::from((string) $request->validated('operation'));
        $value = (string) $request->validated('value');

        try {
            $preview = $bulk->preview($request->validated('product_ids'), $operation, $value);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('admin.products.index')->with('error', $e->getMessage());
        }

        return view('admin.products.bulk-preview', [
            'rows' => $preview['rows'],
            'summary' => $preview['summary'],
            'fingerprint' => $preview['fingerprint'],
            'operation' => $operation,
            'value' => $value,
            'productIds' => array_map('intval', $request->validated('product_ids')),
        ]);
    }

    public function apply(BulkPriceUpdateRequest $request, BulkPricingService $bulk): RedirectResponse
    {
        try {
            $result = $bulk->apply(
                $request->validated('product_ids'),
                BulkOperation::from((string) $request->validated('operation')),
                (string) $request->validated('value'),
                $request->user(),
                (string) $request->validated('reason'),
                (string) $request->validated('fingerprint'),
                $request->boolean('confirm_loss'),
            );
        } catch (StalePreviewException|PriceBelowCostException|InvalidArgumentException $e) {
            return redirect()->route('admin.products.index')->with('error', $e->getMessage());
        }

        return redirect()->route('admin.products.index')->with(
            'status',
            "Updated {$result['changed']} price(s) in one batch (".Str::limit($result['batch_id'], 8, '')."). {$result['unchanged']} unchanged."
        );
    }
}