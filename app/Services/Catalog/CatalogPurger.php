<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes EVERY game and product together with everything that depends on them
 * (orders, provider orders, price history ...), found by following the database foreign keys.
 *
 * Wallet transactions, deposits and the audit log are kept, so balances still add up.
 */
class CatalogPurger
{
    /**
     * What a purge would delete, without deleting anything.
     *
     * @return array{rows:list<array{0:string,1:string,2:int}>,total:int}
     */
    public function preview(): array
    {
        $rows = [];
        $total = 0;

        foreach ($this->plan() as $entry) {
            $count = $this->count($entry);
            $total += $count;
            $rows[] = [$entry['table'], $entry['column'], $count];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Delete everything in ONE transaction (any error undoes all of it).
     *
     * @return int number of rows deleted
     */
    public function purge(): int
    {
        $plan = $this->plan();
        $total = 0;

        foreach ($plan as $entry) {
            $total += $this->count($entry);
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $entry) {
                foreach (array_chunk($entry['ids'], 500) as $chunk) {
                    DB::table($entry['table'])->whereIn($entry['column'], $chunk)->delete();
                }
            }
        });

        Cache::flush();
        Log::warning('Catalog purged: all games, products and their orders were deleted.', ['rows' => $total]);

        return $total;
    }

    /**
     * @return list<array{table:string,column:string,ids:list<int|string>}> children first
     */
    private function plan(): array
    {
        $plan = [];
        $seen = [];

        // The full reset also removes every order. A single game/product delete keeps them
        // (their game_id / product_id become NULL and the saved names stay).
        $this->collect('orders', 'id', DB::table('orders')->pluck('id')->all(), $seen, $plan);
        $this->collect('games', 'id', DB::table('games')->pluck('id')->all(), $seen, $plan);
        $this->collect('products', 'id', DB::table('products')->pluck('id')->all(), $seen, $plan);

        return $plan;
    }

    /**
     * Delete ONE game with its products, fields and price history. Orders are kept
     * (they keep the game and product NAME they were placed with).
     *
     * @return int number of rows deleted
     */
    public function purgeGame(int $gameId): int
    {
        $plan = [];
        $seen = [];
        $this->collect('games', 'id', [$gameId], $seen, $plan);

        return $this->run($plan);
    }

    /**
     * Delete ONE product with its price history. Orders are kept.
     *
     * @return int number of rows deleted
     */
    public function purgeProduct(int $productId): int
    {
        $plan = [];
        $seen = [];
        $this->collect('products', 'id', [$productId], $seen, $plan);

        return $this->run($plan);
    }

    /**
     * @param  list<array{table:string,column:string,ids:list<int|string>}>  $plan
     */
    private function run(array $plan): int
    {
        $total = 0;

        foreach ($plan as $entry) {
            $total += $this->count($entry);
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $entry) {
                foreach (array_chunk($entry['ids'], 500) as $chunk) {
                    DB::table($entry['table'])->whereIn($entry['column'], $chunk)->delete();
                }
            }
        });

        Cache::forget('rechargegames:validatable');

        return $total;
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<string,bool>  $seen
     * @param  list<array{table:string,column:string,ids:list<int|string>}>  $plan
     */
    private function collect(string $table, string $column, array $ids, array &$seen, array &$plan): void
    {
        $ids = $this->unseen($table, $column, array_values(array_unique($ids)), $seen);

        if ($ids === []) {
            return;
        }

        // Rows of a table that have an "id" can have children of their own.
        if ($column === 'id') {
            foreach ($this->restrictingChildren($table) as [$child, $childColumn]) {
                $childHasId = Schema::hasColumn($child, 'id');

                foreach (array_chunk($ids, 500) as $chunk) {
                    $childIds = $childHasId
                        ? DB::table($child)->whereIn($childColumn, $chunk)->pluck('id')->all()
                        : DB::table($child)->whereIn($childColumn, $chunk)->pluck($childColumn)->all();

                    if ($childIds !== []) {
                        $this->collect($child, $childHasId ? 'id' : $childColumn, $childIds, $seen, $plan);
                    }
                }
            }
        }

        $plan[] = ['table' => $table, 'column' => $column, 'ids' => $ids];
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
