<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_offers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('fazercards');
            $table->string('provider_category_id', 64);
            $table->string('provider_offer_id', 64);
            $table->string('name', 160);
            $table->decimal('provider_cost', 18, 4);
            $table->string('currency', 3)->default('USD');
            $table->boolean('is_available')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_category_id', 'provider_offer_id'], 'provider_offers_unique');
            $table->index(['provider', 'provider_category_id', 'is_available'], 'provider_offers_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_offers');
    }
};