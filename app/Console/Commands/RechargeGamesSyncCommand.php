<?php

namespace App\Console\Commands;

use App\Exceptions\ProviderException;
use App\Services\TopUp\CatalogSyncService;
use App\Services\TopUp\RechargeGamesService;
use Illuminate\Console\Command;
use RuntimeException;

class RechargeGamesSyncCommand extends Command
{
    protected $signature = 'rechargegames:sync';

    protected $description = 'Sync the RechargeGames catalog and update the cost and availability of linked products.';

    public function handle(CatalogSyncService $sync, RechargeGamesService $provider): int
    {
        if (! $provider->isConfigured()) {
            $this->error('RECHARGEGAMES_API_KEY is not set in .env.');

            return self::FAILURE;
        }

        try {
            $summary = $sync->sync();
        } catch (ProviderException $e) {
            $this->error('Sync failed: '.$e->getMessage().($e->httpStatus ? " (HTTP {$e->httpStatus})" : ''));

            return self::FAILURE;
        } catch (RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Item', 'Count'], [
            ['Provider categories seen', $summary['categories']],
            ['Categories linked to your games', $summary['linked_categories']],
            ['Offers mirrored', $summary['offers']],
            ['Product costs changed', $summary['price_changes']],
            ['Products now unavailable', $summary['products_unavailable']],
            ['Malformed offers skipped', $summary['skipped_offers']],
        ]);

        foreach ($summary['errors'] as $error) {
            $this->warn($error);
        }

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}