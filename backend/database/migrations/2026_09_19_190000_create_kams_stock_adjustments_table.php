<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kams_stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 32)->unique();
            $table->foreignId('depot_id')->constrained('kams_depots')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->string('type', 32);
            $table->unsignedInteger('quantity');
            $table->integer('quantity_before')->default(0);
            $table->integer('quantity_after')->default(0);
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'depot_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_stock_adjustments');
    }
};
