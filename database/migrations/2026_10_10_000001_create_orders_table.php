<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('game_name', 120);
            $table->string('product_name', 160);
            $table->json('player_fields');
            $table->decimal('provider_cost', 18, 4);
            $table->decimal('selling_price', 18, 4);
            $table->decimal('profit', 18, 4);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('request_token', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'request_token']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('game_id');
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amounts_valid CHECK (selling_price >= 0 AND provider_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};