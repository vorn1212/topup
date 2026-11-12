<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RechargeGames product keys look like "topup/free-fire/brazil/100 Diamonds": longer than 64 characters.
        Schema::table('provider_offers', function (Blueprint $table) {
            $table->string('provider_offer_id', 255)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('provider_offer_id', 255)->nullable()->change();
        });

        Schema::table('provider_orders', function (Blueprint $table) {
            $table->string('offer_id', 255)->change();
        });

        // New rows belong to the new provider.
        foreach (['games', 'products', 'provider_categories', 'provider_offers', 'provider_orders'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN provider SET DEFAULT 'rechargegames'");
        }

        // Old FazerCards links are meaningless for the new provider: the games must be linked again.
        DB::table('games')->where('provider', 'fazercards')->update([
            'provider' => 'rechargegames',
            'provider_category_id' => null,
            'validation_category_id' => null,
        ]);

        // Old products cannot be ordered until they are matched to a RechargeGames product
        // (php artisan rechargegames:relink) or replaced by an import.
        DB::table('products')->where('provider', 'fazercards')->update(['is_available' => false]);
    }

    public function down(): void
    {
        // Intentionally empty: the old provider links cannot be restored.
    }
};
