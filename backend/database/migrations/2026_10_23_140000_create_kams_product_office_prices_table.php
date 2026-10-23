<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kams_product_office_prices')) {
            return;
        }

        Schema::create('kams_product_office_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('kams_products')->cascadeOnDelete();
            $table->foreignId('office_id')->constrained('offices')->cascadeOnDelete();
            $table->decimal('sale_price', 14, 2);
            $table->timestamps();
            $table->unique(['product_id', 'office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_product_office_prices');
    }
};
