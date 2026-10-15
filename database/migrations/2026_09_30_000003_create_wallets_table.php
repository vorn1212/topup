<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->decimal('balance', 18, 4)->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');

        // Backfill: every existing user gets a wallet.
        DB::insert(
            "INSERT INTO wallets (user_id, currency, balance, status, created_at, updated_at)
             SELECT id, ?, 0, 'active', NOW(), NOW() FROM users",
            [config('topup.currency', 'USD')]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};