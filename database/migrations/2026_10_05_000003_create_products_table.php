<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('fazercards');
            $table->string('provider_offer_id', 64)->nullable();
            $table->string('sku', 64)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->decimal('provider_cost', 18, 4)->default(0);
            $table->string('markup_type', 20)->default('fixed_markup');
            $table->decimal('markup_value', 18, 4)->default(0);
            $table->decimal('selling_price', 18, 4)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 20)->default('active');
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('provider_data')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_offer_id']);
            $table->index(['game_id', 'status', 'sort_order']);
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_selling_price_non_negative CHECK (selling_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_provider_cost_non_negative CHECK (provider_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};