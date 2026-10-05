<?php

use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockBalance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_products', function (Blueprint $table) {
            $table->decimal('avg_cost', 14, 2)->default(0)->after('cost');
            $table->decimal('seller_share_percent', 5, 2)->default(50)->after('avg_cost');
        });

        Schema::table('kams_depots', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('office_id')->constrained('kams_members')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->after('member_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('kams_stock_balances', function (Blueprint $table) {
            $table->string('condition', 32)->default('available')->after('product_id');
        });

        Schema::table('kams_stock_balances', function (Blueprint $table) {
            // Unique (depot_id, product_id) is the supporting index of the depot FK.
            $table->index('depot_id');
        });

        Schema::table('kams_stock_balances', function (Blueprint $table) {
            $table->dropUnique(['depot_id', 'product_id']);
            $table->unique(['depot_id', 'product_id', 'condition']);
        });

        Schema::table('kams_stock_movements', function (Blueprint $table) {
            $table->string('status', 20)->default('posted')->after('type');
            $table->string('condition', 32)->default('available')->after('status');
            $table->foreignId('approved_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('kams_stock_transfers', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('transferred_at');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->foreignId('dispatched_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('kams_stock_transfer_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 2)->default(0)->after('quantity');
        });

        Schema::create('kams_stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->foreignId('warehouse_id')->constrained('kams_depots')->restrictOnDelete();
            $table->string('supplier_name')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('document_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_stock_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('kams_stock_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('kams_stock_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->foreignId('member_id')->constrained('kams_members')->restrictOnDelete();
            $table->foreignId('depot_id')->nullable()->constrained('kams_depots')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('kams_clients')->nullOnDelete();
            $table->string('client_name')->nullable();
            $table->string('payment_status', 20)->default('unpaid');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->decimal('margin', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sold_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_stock_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('kams_stock_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('kams_stock_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->string('type', 32);
            $table->string('status', 32)->default('requested');
            $table->foreignId('sale_id')->nullable()->constrained('kams_stock_sales')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('kams_members')->nullOnDelete();
            $table->foreignId('from_depot_id')->nullable()->constrained('kams_depots')->nullOnDelete();
            $table->foreignId('to_depot_id')->nullable()->constrained('kams_depots')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('condition', 32)->default('available');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_stock_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('kams_stock_returns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('kams_seller_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('kams_stock_sales')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('kams_members')->restrictOnDelete();
            $table->string('status', 20)->default('estimated');
            $table->decimal('estimated_amount', 14, 2)->default(0);
            $table->decimal('adjusted_amount', 14, 2)->default(0);
            $table->decimal('payable_amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->timestamps();
        });

        KamsProduct::query()->each(function (KamsProduct $product) {
            $product->forceFill([
                'avg_cost' => (float) $product->cost,
                'seller_share_percent' => 50,
            ])->save();
        });

        KamsStockBalance::query()->whereNull('condition')->orWhere('condition', '')->update(['condition' => 'available']);

        $open = KamsStockAssignment::query()->where('status', 'open')->with('items', 'member')->get();
        foreach ($open as $assignment) {
            $member = $assignment->member;
            if (! $member) {
                continue;
            }
            $seller = KamsDepot::query()->firstOrCreate(
                ['company_id' => $member->company_id, 'member_id' => $member->id, 'kind' => 'seller'],
                [
                    'name' => 'Stock '.$member->full_name,
                    'code' => 'VEN-'.$member->member_code,
                    'office_id' => $member->office_id,
                    'status' => 'active',
                ]
            );
            foreach ($assignment->items as $line) {
                $qty = (int) $line->quantity_out - (int) $line->quantity_returned - (int) $line->quantity_lost;
                if ($qty <= 0) {
                    continue;
                }
                $balance = KamsStockBalance::query()->firstOrCreate(
                    ['depot_id' => $seller->id, 'product_id' => $line->product_id, 'condition' => 'available'],
                    ['company_id' => $assignment->company_id, 'quantity' => 0]
                );
                $balance->quantity = (int) $balance->quantity + $qty;
                $balance->save();
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_seller_commissions');
        Schema::dropIfExists('kams_stock_return_items');
        Schema::dropIfExists('kams_stock_returns');
        Schema::dropIfExists('kams_stock_sale_items');
        Schema::dropIfExists('kams_stock_sales');
        Schema::dropIfExists('kams_stock_receipt_items');
        Schema::dropIfExists('kams_stock_receipts');

        Schema::table('kams_stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
        Schema::table('kams_stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('dispatched_by');
            $table->dropColumn('received_at');
        });
        Schema::table('kams_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['status', 'condition']);
        });
        Schema::table('kams_stock_balances', function (Blueprint $table) {
            $table->dropUnique(['depot_id', 'product_id', 'condition']);
            $table->unique(['depot_id', 'product_id']);
            $table->dropColumn('condition');
        });

        Schema::table('kams_stock_balances', function (Blueprint $table) {
            $table->dropIndex(['depot_id']);
        });
        Schema::table('kams_depots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_user_id');
            $table->dropConstrainedForeignId('member_id');
        });
        Schema::table('kams_products', function (Blueprint $table) {
            $table->dropColumn(['avg_cost', 'seller_share_percent']);
        });
    }
};
