<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['games', 'products', 'provider_categories', 'provider_offers', 'provider_orders'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN provider SET DEFAULT 'hariki'");
        }

        // RechargeGames links mean nothing to Hariki: games must be linked again (php artisan hariki:relink).
        DB::table('games')->where('provider', 'rechargegames')->update([
            'provider' => 'hariki',
            'provider_category_id' => null,
            'validation_category_id' => null,
        ]);

        // Products stay as they are (names, prices, markups) but cannot be ordered until re-attached.
        DB::table('products')->where('provider', 'rechargegames')->update(['is_available' => false]);
    }

    public function down(): void
    {
        // Intentionally empty: the old provider links cannot be restored.
    }
};
