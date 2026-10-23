<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kams_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('kams_depots')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('kams_clients')->nullOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('reference', 32);
            $table->string('status', 24)->default('ordered');
            $table->date('ordered_at');
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kams_purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('kams_purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_purchase_order_items');
        Schema::dropIfExists('kams_purchase_orders');
    }
};
