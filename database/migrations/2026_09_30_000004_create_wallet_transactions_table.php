<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('direction', 6);
            $table->decimal('amount', 18, 4);
            $table->decimal('balance_before', 18, 4);
            $table->decimal('balance_after', 18, 4);
            $table->string('currency', 3)->default('USD');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('completed');
            $table->string('idempotency_key', 120)->unique();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
        });

        DB::statement('ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_positive CHECK (amount > 0)');
        DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_direction_valid CHECK (direction IN ('credit','debit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};