<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_offer_id']);
            $table->unique(['game_id', 'provider', 'provider_offer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['game_id', 'provider', 'provider_offer_id']);
            $table->unique(['provider', 'provider_offer_id']);
        });
    }
};