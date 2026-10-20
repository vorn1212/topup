<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Picture paths only. No money, so nothing here can touch prices or wallets.
        Schema::table('products', function (Blueprint $table) {
            $table->string('icon')->nullable()->after('description');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->string('package_icon')->nullable()->after('banner');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('icon');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('package_icon');
        });
    }
};
