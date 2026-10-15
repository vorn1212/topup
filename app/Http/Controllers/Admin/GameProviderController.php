<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MarkupType;
use App\Exceptions\ProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportOffersRequest;
use App\Models\Game;
use App\Services\TopUp\CatalogSyncService;
use App\Services\TopUp\ProviderImportService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class GameProviderController extends Controller
{
    public function refresh(Game $game, CatalogSyncService $sync): RedirectResponse
    {
        if (blank($game->provider_category_id)) {
            return back()->with('error', 'Link this game to a provider category first.');
        }

        try {
            $summary = $sync->syncCategoryNow($game->provider_category_id);
        } catch (ProviderException|RuntimeException $e) {
            return back()->with('error', 'Refresh failed: '.$e->getMessage());
        }

        if ($summary['errors'] !== []) {
            return back()->with('error', implode(' ', array_slice($summary['errors'], 0, 3)));
        }

        return back()->with('status', sprintf(
            'Refreshed: %d offer(s) mirrored, %d product cost change(s).',
            $summary['offers'],
            $summary['price_changes']
        ));
    }

    public function importFields(Game $game, ProviderImportService $import): RedirectResponse
    {
        try {
            $result = $import->importFields($game);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Player fields: {$result['created']} added, {$result['skipped']} already existed.");
    }

    public function importOffers(ImportOffersRequest $request, Game $game, ProviderImportService $import): RedirectResponse
    {
        try {
            $result = $import->importOffers(
                $game,
                MarkupType::from((string) $request->validated('markup_type')),
                (string) $request->validated('markup_value'),
                $request->boolean('make_active'),
                $request->user(),
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Products: {$result['created']} created, {$result['skipped']} already existed.";

        if ($result['failed'] !== []) {
            $message .= ' '.count($result['failed']).' skipped: '.implode(' | ', array_slice($result['failed'], 0, 3));

            return $result['created'] === 0
                ? back()->with('error', $message)
                : back()->with('status', $message);
        }

        return back()->with('status', $message);
    }
}