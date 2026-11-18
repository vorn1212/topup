<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogPurger;
use Illuminate\Console\Command;

/**
 * Same as the super-admin page Admin > Delete all games and products. Dry run unless --force is given.
 */
class PurgeCatalogCommand extends Command
{
    public const CONFIRMATION = 'Delete ALL games, products and their order history? This cannot be undone.';

    protected $signature = 'catalog:purge {--force : Really delete (without it, only count)}';

    protected $description = 'Delete all games and products, including their order history. Dry run unless --force is given.';

    public function handle(CatalogPurger $purger): int
    {
        $preview = $purger->preview();

        $this->table(['Table', 'Matched by', 'Rows to delete'], $preview['rows']);

        if (! $this->option('force')) {
            $this->warn("Dry run: {$preview['total']} row(s) would be deleted. Nothing was changed. Add --force to delete.");

            return self::SUCCESS;
        }

        if (! $this->confirm(self::CONFIRMATION, false)) {
            $this->line('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        $deleted = $purger->purge();

        $this->info("Deleted {$deleted} row(s). The shop catalog is now empty.");

        return self::SUCCESS;
    }
}
