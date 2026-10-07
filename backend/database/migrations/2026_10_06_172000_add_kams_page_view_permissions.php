<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, array{name: string, group: string}>
     */
    private array $definitions = [
        'kams.stock.warehouses.view' => ['name' => 'View warehouses', 'group' => 'kams.stock'],
        'kams.stock.depots.view' => ['name' => 'View depots', 'group' => 'kams.stock'],
        'kams.finance.accounts.view' => ['name' => 'View agent accounts', 'group' => 'kams.finance'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private array $backfill = [
        'kams.stock.warehouses.view' => ['kams.products.view', 'kams.stock.view'],
        'kams.stock.depots.view' => ['kams.products.view', 'kams.stock.view'],
        'kams.finance.accounts.view' => ['kams.finance.dashboard.view'],
    ];

    public function up(): void
    {
        foreach ($this->definitions as $slug => $meta) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => $meta['name'],
                    'group' => $meta['group'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        foreach ($this->backfill as $targetSlug => $sourceSlugs) {
            $targetId = DB::table('permissions')->where('slug', $targetSlug)->value('id');
            if (! $targetId) {
                continue;
            }

            $roleIds = DB::table('permission_role')
                ->whereIn('permission_id', function ($query) use ($sourceSlugs) {
                    $query->select('id')->from('permissions')->whereIn('slug', $sourceSlugs);
                })
                ->pluck('role_id')
                ->unique();

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $targetId],
                    []
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('slug', array_keys($this->definitions))
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
