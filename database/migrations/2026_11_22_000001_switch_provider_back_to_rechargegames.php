<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['games', 'products', 'provider_categories', 'provider_offers', 'provider_orders'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN provider SET DEFAULT 'rechargegames'");
        }

        // Hariki game codes (like "mlbb") mean nothing to RechargeGames ("free-fire/brazil"):
        // games must be linked again with: php artisan rechargegames:relink <game-slug> <category>
        DB::table('games')->where('provider', 'hariki')->update([
            'provider' => 'rechargegames',
            'provider_category_id' => null,
            'validation_category_id' => null,
        ]);

        // Products keep their names, prices and markups, but cannot be ordered until re-attached.
        DB::table('products')->where('provider', 'hariki')->update(['is_available' => false]);
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
