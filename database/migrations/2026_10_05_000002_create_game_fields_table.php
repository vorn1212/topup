<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('provider_field_name', 60);
            $table->string('label', 80);
            $table->string('type', 20)->default('text');
            $table->boolean('required')->default(true);
            $table->string('validation_rules', 255)->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['game_id', 'provider_field_name']);
            $table->index(['game_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_fields');
    }
};