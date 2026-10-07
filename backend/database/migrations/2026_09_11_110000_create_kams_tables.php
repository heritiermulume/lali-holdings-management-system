<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kams_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('segment', 64)->default('retail');
            $table->enum('status', ['active', 'inactive', 'prospect'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kams_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->string('name');
            $table->string('category')->nullable();
            $table->string('collection')->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->enum('status', ['active', 'draft', 'archived'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('kams_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('kams_clients')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->enum('status', ['draft', 'confirmed', 'fulfilled', 'cancelled'])->default('confirmed');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->date('ordered_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('kams_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_order_items');
        Schema::dropIfExists('kams_orders');
        Schema::dropIfExists('kams_products');
        Schema::dropIfExists('kams_clients');
    }
};
