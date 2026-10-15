<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('deposit_number', 32)->unique();
            $table->decimal('amount', 18, 4);
            $table->string('currency', 3)->default('USD');
            $table->string('payment_method', 30)->default('aba_payway');
            $table->string('payment_reference', 64)->unique();
            $table->string('aba_transaction_id', 100)->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement('ALTER TABLE wallet_deposits ADD CONSTRAINT wallet_deposits_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_deposits');
    }
};