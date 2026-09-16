<?php

use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsStockBalance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_products', function (Blueprint $table) {
            $table->decimal('cost', 14, 2)->default(0)->after('price');
        });

        Schema::table('kams_depots', function (Blueprint $table) {
            $table->string('kind', 20)->default('depot')->after('office_id');
            $table->foreignId('province_id')->nullable()->after('kind')->constrained()->nullOnDelete();
        });

        Schema::create('kams_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depot_id')->constrained('kams_depots')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['depot_id', 'product_id']);
        });

        Schema::create('kams_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->foreignId('from_depot_id')->nullable()->constrained('kams_depots')->nullOnDelete();
            $table->foreignId('to_depot_id')->nullable()->constrained('kams_depots')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('kams_members')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('type', 32);
            $table->nullableMorphs('reference');
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('kams_stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->foreignId('from_depot_id')->constrained('kams_depots')->restrictOnDelete();
            $table->foreignId('to_depot_id')->constrained('kams_depots')->restrictOnDelete();
            $table->string('status', 20)->default('completed');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('kams_stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });

        Schema::create('kams_stock_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40);
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained('kams_depots')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('kams_members')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->decimal('expected_amount', 14, 2)->default(0);
            $table->decimal('collected_amount', 14, 2)->default(0);
            $table->decimal('profit_amount', 14, 2)->default(0);
            $table->decimal('loss_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('kams_stock_assignment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('kams_stock_assignments')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('kams_products')->restrictOnDelete();
            $table->unsignedInteger('quantity_out')->default(0);
            $table->unsignedInteger('quantity_returned')->default(0);
            $table->unsignedInteger('quantity_lost')->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->timestamps();
        });

        KamsProduct::query()->where('cost', 0)->where('price', '>', 0)->each(function (KamsProduct $product) {
            $product->forceFill(['cost' => round(((float) $product->price) * 0.6, 2)])->save();
        });

        $companies = KamsProduct::query()->pluck('company_id')->unique();
        foreach ($companies as $companyId) {
            $warehouse = KamsDepot::query()->firstOrCreate(
                ['company_id' => $companyId, 'code' => 'ENT-MAIN'],
                [
                    'name' => 'Entrepôt principal',
                    'kind' => 'warehouse',
                    'status' => 'active',
                    'office_id' => null,
                ]
            );
            $warehouse->forceFill(['kind' => 'warehouse', 'office_id' => null])->save();
            KamsDepot::query()->where('company_id', $companyId)->whereNotNull('office_id')->update(['kind' => 'depot']);

            KamsProduct::query()->where('company_id', $companyId)->where('stock', '>', 0)->each(function (KamsProduct $product) use ($warehouse, $companyId) {
                KamsStockBalance::query()->updateOrCreate(
                    ['depot_id' => $warehouse->id, 'product_id' => $product->id],
                    ['company_id' => $companyId, 'quantity' => (int) $product->stock]
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_stock_assignment_items');
        Schema::dropIfExists('kams_stock_assignments');
        Schema::dropIfExists('kams_stock_transfer_items');
        Schema::dropIfExists('kams_stock_transfers');
        Schema::dropIfExists('kams_stock_movements');
        Schema::dropIfExists('kams_stock_balances');
        Schema::table('kams_depots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('province_id');
            $table->dropColumn('kind');
        });
        Schema::table('kams_products', function (Blueprint $table) {
            $table->dropColumn('cost');
        });
    }
};
