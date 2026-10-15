<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('fazercards');
            $table->string('provider_order_id', 40)->nullable();
            $table->string('idempotency_key', 80)->unique();
            $table->string('category_id', 64);
            $table->string('offer_id', 64);
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_order_id']);
            $table->index(['status', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_orders');
    }
};