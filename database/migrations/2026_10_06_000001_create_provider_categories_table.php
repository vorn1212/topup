<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_categories', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('fazercards');
            $table->string('provider_category_id', 64);
            $table->string('name', 160);
            $table->string('note', 255)->nullable();
            $table->json('fields')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_categories');
    }
};