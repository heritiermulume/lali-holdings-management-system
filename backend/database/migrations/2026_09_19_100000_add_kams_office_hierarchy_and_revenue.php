<?php

use App\Core\Support\RoleCatalog;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->foreignId('parent_office_id')->nullable()->after('company_id')->constrained('offices')->nullOnDelete();
            $table->foreignId('creator_user_id')->nullable()->after('parent_office_id')->constrained('users')->nullOnDelete();
            $table->foreignId('manager_user_id')->nullable()->after('creator_user_id')->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->after('manager_user_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->foreignId('assistant_manager_member_id')->nullable()->after('manager_member_id')->constrained('kams_members')->nullOnDelete();
            $table->decimal('fixed_salary', 12, 2)->default(0)->after('notes');
        });

        Schema::table('kams_products', function (Blueprint $table) {
            $table->decimal('manager_share_percent', 5, 2)->nullable()->after('seller_share_percent');
        });

        Schema::create('kams_office_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('field', 64);
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['office_id', 'field']);
        });

        Schema::create('kams_revenue_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('role_slug', 64);
            $table->string('label');
            $table->decimal('percent', 8, 4)->default(0);
            $table->decimal('divisor', 8, 4)->nullable();
            $table->string('basis', 32);
            $table->string('scope', 32);
            $table->string('paid_from', 64)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('kams_revenue_rule_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('kams_revenue_rules')->cascadeOnDelete();
            $table->string('code', 64);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();
        });

        Schema::create('kams_revenue_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('kams_stock_sales')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('kams_stock_sale_items')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('kams_products')->nullOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('kams_revenue_rules')->nullOnDelete();
            $table->string('rule_code', 64);
            $table->string('role_slug', 64);
            $table->foreignId('beneficiary_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('beneficiary_member_id')->nullable()->constrained('kams_members')->nullOnDelete();
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('percent', 8, 4)->default(0);
            $table->decimal('divisor', 8, 4)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('formula')->nullable();
            $table->string('status', 20)->default('accrued');
            $table->foreignId('parent_share_id')->nullable()->constrained('kams_revenue_shares')->nullOnDelete();
            $table->json('snapshot')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->index(['sale_id', 'rule_code']);
            $table->index(['beneficiary_member_id', 'status']);
        });

        Schema::create('kams_salary_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('kams_members')->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained('offices')->nullOnDelete();
            $table->string('period', 7);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('posted');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['member_id', 'period']);
        });

        $permissionIds = Permission::query()->pluck('id', 'slug');
        foreach (RoleCatalog::all() as $roleData) {
            $role = Role::query()->where('slug', $roleData['slug'])->first();
            if (! $role || $role->hasFullAccess()) {
                continue;
            }
            $slugs = RoleCatalog::permissionSlugsFor($roleData['slug']) ?? [];
            $role->permissions()->sync($permissionIds->only($slugs)->values());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kams_salary_entries');
        Schema::dropIfExists('kams_revenue_shares');
        Schema::dropIfExists('kams_revenue_rule_histories');
        Schema::dropIfExists('kams_revenue_rules');
        Schema::dropIfExists('kams_office_histories');

        Schema::table('kams_products', function (Blueprint $table) {
            $table->dropColumn('manager_share_percent');
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assistant_manager_member_id');
            $table->dropColumn('fixed_salary');
        });

        Schema::table('offices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recorded_by_user_id');
            $table->dropConstrainedForeignId('manager_user_id');
            $table->dropConstrainedForeignId('creator_user_id');
            $table->dropConstrainedForeignId('parent_office_id');
        });
    }
};
