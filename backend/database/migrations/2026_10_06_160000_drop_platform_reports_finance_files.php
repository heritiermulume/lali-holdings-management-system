<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform transversal permissions only — keep KAMS finance (kams.finance.*).
        $permissionIds = DB::table('permissions')
            ->whereIn('group', ['finance', 'reports', 'files'])
            ->orWhere(function ($query) {
                $query->where('slug', 'like', 'reports.%')
                    ->orWhere('slug', 'like', 'files.%')
                    ->orWhere(function ($inner) {
                        $inner->where('slug', 'like', 'finance.%')
                            ->where('slug', 'not like', 'kams.%');
                    });
            })
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('financial_snapshots');
    }

    public function down(): void
    {
        if (! Schema::hasTable('financial_snapshots')) {
            Schema::create('financial_snapshots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('source_module', 64)->nullable();
                $table->string('period', 16);
                $table->decimal('revenue', 18, 2)->default(0);
                $table->decimal('expenses', 18, 2)->default(0);
                $table->decimal('profit', 18, 2)->default(0);
                $table->decimal('cash', 18, 2)->default(0);
                $table->decimal('assets', 18, 2)->default(0);
                $table->decimal('liabilities', 18, 2)->default(0);
                $table->decimal('receivables', 18, 2)->default(0);
                $table->decimal('payables', 18, 2)->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'source_module', 'period']);
            });
        }

        foreach ([
            ['slug' => 'finance.view', 'group' => 'finance', 'name' => 'View finances'],
            ['slug' => 'finance.create', 'group' => 'finance', 'name' => 'Create financial records'],
            ['slug' => 'finance.update', 'group' => 'finance', 'name' => 'Update financial records'],
            ['slug' => 'finance.approve', 'group' => 'finance', 'name' => 'Approve financial records'],
            ['slug' => 'finance.export', 'group' => 'finance', 'name' => 'Export financial data'],
            ['slug' => 'reports.view', 'group' => 'reports', 'name' => 'View reports'],
            ['slug' => 'reports.export', 'group' => 'reports', 'name' => 'Export reports'],
            ['slug' => 'reports.print', 'group' => 'reports', 'name' => 'Print reports'],
            ['slug' => 'files.manage', 'group' => 'files', 'name' => 'Manage files'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'group' => $permission['group'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
};
