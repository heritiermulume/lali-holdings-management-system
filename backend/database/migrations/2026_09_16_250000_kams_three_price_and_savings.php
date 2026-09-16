<?php

use App\Modules\Kams\Models\KamsMemberOpeningPosition;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Models\KamsSavingsEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_products', function (Blueprint $table) {
            $table->decimal('field_price', 14, 2)->default(0)->after('price');
            $table->decimal('manager_price', 14, 2)->default(0)->after('field_price');
            $table->decimal('company_price', 14, 2)->default(0)->after('manager_price');
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->foreignId('manager_member_id')->nullable()->after('trainer_user_id')->constrained('kams_members')->nullOnDelete();
        });

        Schema::table('kams_stock_sales', function (Blueprint $table) {
            $table->decimal('field_total', 14, 2)->default(0)->after('subtotal');
            $table->decimal('manager_total', 14, 2)->default(0)->after('field_total');
            $table->decimal('company_total', 14, 2)->default(0)->after('manager_total');
            $table->decimal('seller_margin', 14, 2)->default(0)->after('company_total');
            $table->decimal('manager_margin', 14, 2)->default(0)->after('seller_margin');
            $table->foreignId('manager_member_id')->nullable()->after('member_id')->constrained('kams_members')->nullOnDelete();
        });

        Schema::table('kams_stock_sale_items', function (Blueprint $table) {
            $table->decimal('field_price', 14, 2)->default(0)->after('unit_price');
            $table->decimal('manager_price', 14, 2)->default(0)->after('field_price');
            $table->decimal('company_price', 14, 2)->default(0)->after('manager_price');
            $table->decimal('seller_margin', 14, 2)->default(0)->after('company_price');
            $table->decimal('manager_margin', 14, 2)->default(0)->after('seller_margin');
        });

        Schema::create('kams_savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('kams_members')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['member_id', 'kind']);
        });

        Schema::create('kams_savings_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('kams_savings_accounts')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('kams_members')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('type', 20);
            $table->decimal('amount', 14, 2);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->date('occurred_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_type', 'source_id', 'kind', 'type']);
        });

        Schema::create('kams_daily_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->date('settled_on');
            $table->string('status', 20)->default('open');
            $table->decimal('field_total', 14, 2)->default(0);
            $table->decimal('seller_savings', 14, 2)->default(0);
            $table->decimal('manager_savings', 14, 2)->default(0);
            $table->decimal('company_share', 14, 2)->default(0);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'office_id', 'settled_on']);
        });

        KamsProduct::query()->each(function (KamsProduct $product) {
            $field = (float) $product->price;
            $company = (float) $product->cost ?: round($field * 0.6, 2);
            $manager = round(($field + $company) / 2, 2);
            $product->forceFill([
                'field_price' => $field,
                'manager_price' => $manager,
                'company_price' => $company,
            ])->save();
        });

        foreach (KamsMemberOpeningPosition::query()->whereIn('category', ['distributor_savings', 'manager_savings'])->get() as $row) {
            $kind = $row->category === 'manager_savings' ? 'manager' : 'guy';
            $account = KamsSavingsAccount::query()->firstOrCreate(
                ['member_id' => $row->member_id, 'kind' => $kind],
                ['company_id' => $row->company_id, 'balance' => 0]
            );
            if ((float) $row->amount == 0.0) {
                continue;
            }
            KamsSavingsEntry::query()->create([
                'company_id' => $row->company_id,
                'account_id' => $account->id,
                'member_id' => $row->member_id,
                'kind' => $kind,
                'type' => 'opening',
                'amount' => (float) $row->amount,
                'source_type' => KamsMemberOpeningPosition::class,
                'source_id' => $row->id,
                'occurred_on' => now()->toDateString(),
                'notes' => 'Opening balance',
            ]);
            $account->balance = (float) KamsSavingsEntry::query()->where('account_id', $account->id)->sum('amount');
            $account->save();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_daily_settlements');
        Schema::dropIfExists('kams_savings_entries');
        Schema::dropIfExists('kams_savings_accounts');
        Schema::table('kams_stock_sale_items', function (Blueprint $table) {
            $table->dropColumn(['field_price', 'manager_price', 'company_price', 'seller_margin', 'manager_margin']);
        });
        Schema::table('kams_stock_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_member_id');
            $table->dropColumn(['field_total', 'manager_total', 'company_total', 'seller_margin', 'manager_margin']);
        });
        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_member_id');
        });
        Schema::table('kams_products', function (Blueprint $table) {
            $table->dropColumn(['field_price', 'manager_price', 'company_price']);
        });
    }
};
