<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            // sha256 of the raw body: the same delivery arriving twice is stored once.
            $table->string('dedupe_key', 64);
            $table->string('event_type', 60)->nullable();
            $table->string('provider_order_id', 40)->nullable();
            // received -> processed | ignored
            $table->string('status', 20)->default('received');
            $table->string('note', 255)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'dedupe_key']);
            $table->index(['status', 'created_at']);
            $table->index('provider_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};