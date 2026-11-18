<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A game or product can be deleted at any time. Its past orders stay (reports keep working with the
 * saved game_name / product_name): orders.game_id and orders.product_id become NULL instead of blocking.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (['game_id' => 'games', 'product_id' => 'products'] as $column => $parent) {
            $foreign = DB::select(
                'SELECT CONSTRAINT_NAME AS name FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ?',
                ['orders', $column, $parent]
            );

            foreach ($foreign as $row) {
                DB::statement("ALTER TABLE `orders` DROP FOREIGN KEY `{$row->name}`");
            }

            DB::statement("ALTER TABLE `orders` MODIFY `{$column}` BIGINT UNSIGNED NULL");
            DB::statement("ALTER TABLE `orders` ADD CONSTRAINT `orders_{$column}_foreign` FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`id`) ON DELETE SET NULL");
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
