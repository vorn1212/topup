<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('old_price', 18, 4);
            $table->decimal('new_price', 18, 4);
            $table->decimal('old_markup', 18, 4);   // effective markup = selling - cost
            $table->decimal('new_markup', 18, 4);
            $table->decimal('old_cost', 18, 4);
            $table->decimal('new_cost', 18, 4);
            $table->string('old_markup_type', 20);
            $table->string('new_markup_type', 20);
            $table->decimal('old_markup_value', 18, 4);
            $table->decimal('new_markup_value', 18, 4);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255);
            $table->string('batch_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index('batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_histories');
    }
};