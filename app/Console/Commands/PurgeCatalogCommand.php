<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes EVERY game and product, together with everything that depends on them
 * (orders, provider orders, price history ...), found by following the database foreign keys.
 *
 * Wallet transactions, deposits and the audit log are kept, so balances still add up.
 * Without --force it only counts what would be deleted.
 */
class PurgeCatalogCommand extends Command
{
    public const CONFIRMATION = 'Delete ALL games, products and their order history? This cannot be undone.';

    protected $signature = 'catalog:purge {--force : Really delete (without it, only count)}';

    protected $description = 'Delete all games and products, including their order history. Dry run unless --force is given.';

    /** @var list<array{table:string,column:string,ids:list<int|string>}> children first */
    private array $plan = [];

    public function handle(): int
    {
        $this->plan = [];
        $seen = [];

        $this->collect('games', 'id', DB::table('games')->pluck('id')->all(), $seen);
        $this->collect('products', 'id', DB::table('products')->pluck('id')->all(), $seen);

        $rows = [];
        $total = 0;

        foreach ($this->plan as $entry) {
            $count = $this->count($entry);
            $total += $count;
            $rows[] = [$entry['table'], $entry['column'], $count];
        }

        $this->table(['Table', 'Matched by', 'Rows to delete'], $rows);

        if (! $this->option('force')) {
            $this->warn("Dry run: {$total} row(s) would be deleted. Nothing was changed. Add --force to delete.");

            return self::SUCCESS;
        }

        if (! $this->confirm(self::CONFIRMATION, false)) {
            $this->line('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () {
            foreach ($this->plan as $entry) {
                foreach (array_chunk($entry['ids'], 500) as $chunk) {
                    DB::table($entry['table'])->whereIn($entry['column'], $chunk)->delete();
                }
            }
        });

        Cache::flush();
        Log::warning('Catalog purged: all games, products and their orders were deleted.', ['rows' => $total]);

        $this->info("Deleted {$total} row(s). The shop catalog is now empty.");

        return self::SUCCESS;
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<string,bool>  $seen
     */
    private function collect(string $table, string $column, array $ids, array &$seen): void
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return;
        }

        $ids = $this->unseen($table, $column, $ids, $seen);

        if ($ids === []) {
            return;
        }

        // Rows of a table that have an "id" can have children of their own.
        if ($column === 'id') {
            foreach ($this->restrictingChildren($table) as [$child, $childColumn]) {
                foreach (array_chunk($ids, 500) as $chunk) {
                    $childIds = Schema::hasColumn($child, 'id')
                        ? DB::table($child)->whereIn($childColumn, $chunk)->pluck('id')->all()
                        : DB::table($child)->whereIn($childColumn, $chunk)->pluck($childColumn)->all();

                    if (Schema::hasColumn($child, 'id')) {
                        $this->collect($child, 'id', $childIds, $seen);
                    } elseif ($childIds !== []) {
                        $this->collect($child, $childColumn, $childIds, $seen);
                    }
                }
            }
        }

        $this->plan[] = ['table' => $table, 'column' => $column, 'ids' => $ids];
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<string,bool>  $seen
     * @return list<int|string>
     */
    private function unseen(string $table, string $column, array $ids, array &$seen): array
    {
        $fresh = [];

        foreach ($ids as $id) {
            $key = "{$table}.{$column}.{$id}";

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $fresh[] = $id;
            }
        }

        return $fresh;
    }

    /**
     * Tables whose foreign key to $table blocks the delete (RESTRICT / NO ACTION).
     * CASCADE and SET NULL are handled by the database itself.
     *
     * @return list<array{0:string,1:string}>
     */
    private function restrictingChildren(string $table): array
    {
        $rows = DB::select(
            'SELECT k.TABLE_NAME AS child, k.COLUMN_NAME AS col
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND k.REFERENCED_TABLE_NAME = ?
               AND r.DELETE_RULE IN (\'RESTRICT\', \'NO ACTION\')',
            [$table]
        );

        return array_map(fn ($row) => [(string) $row->child, (string) $row->col], $rows);
    }

    /**
     * @param  array{table:string,column:string,ids:list<int|string>}  $entry
     */
    private function count(array $entry): int
    {
        $total = 0;

        foreach (array_chunk($entry['ids'], 500) as $chunk) {
            $total += DB::table($entry['table'])->whereIn($entry['column'], $chunk)->count();
        }

        return $total;
    }
}
