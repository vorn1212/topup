<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_deposits', function (Blueprint $table) {
            $table->string('gateway_stage', 20)->nullable()->after('status')->index();
            $table->timestamp('qr_expires_at')->nullable()->after('expires_at');
            $table->string('gateway_error', 255)->nullable()->after('qr_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_deposits', function (Blueprint $table) {
            $table->dropIndex(['gateway_stage']);
            $table->dropColumn(['gateway_stage', 'qr_expires_at', 'gateway_error']);
        });
    }
};