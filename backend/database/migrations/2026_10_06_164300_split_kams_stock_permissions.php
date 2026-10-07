<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $dropSlugs = [
        'kams.recruitment.approve',
        'kams.orders.create',
        'kams.orders.update',
        'kams.orders.export',
        'kams.orders.print',
    ];

    /** @var array<string, array{name: string, group: string}> */
    private array $createSlugs = [
        'kams.stock.view' => ['name' => 'View stock operations', 'group' => 'kams.stock'],
        'kams.stock.update' => ['name' => 'Update stock operations', 'group' => 'kams.stock'],
    ];

    public function up(): void
    {
        foreach ($this->createSlugs as $slug => $meta) {
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

        $stockViewId = DB::table('permissions')->where('slug', 'kams.stock.view')->value('id');
        $stockUpdateId = DB::table('permissions')->where('slug', 'kams.stock.update')->value('id');
        $productsViewId = DB::table('permissions')->where('slug', 'kams.products.view')->value('id');
        $productsUpdateId = DB::table('permissions')->where('slug', 'kams.products.update')->value('id');

        if ($stockViewId && $productsViewId) {
            $roleIds = DB::table('permission_role')
                ->where('permission_id', $productsViewId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $stockViewId],
                    []
                );
            }
        }

        if ($stockUpdateId && $productsUpdateId) {
            $roleIds = DB::table('permission_role')
                ->where('permission_id', $productsUpdateId)
                ->pluck('role_id');

            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $stockUpdateId],
                    []
                );
            }
        }

        // Keep orders.view grouped with stock in the permission tree.
        DB::table('permissions')
            ->where('slug', 'kams.orders.view')
            ->update(['group' => 'kams.stock', 'updated_at' => now()]);

        DB::table('permissions')
            ->whereIn('slug', ['kams.products.view', 'kams.products.create', 'kams.products.update', 'kams.products.delete'])
            ->update(['group' => 'kams.stock', 'updated_at' => now()]);

        $dropIds = DB::table('permissions')
            ->whereIn('slug', $this->dropSlugs)
            ->pluck('id');

        if ($dropIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $dropIds)->delete();
            DB::table('permissions')->whereIn('id', $dropIds)->delete();
        }
    }

    public function down(): void
    {
        foreach ($this->dropSlugs as $slug) {
            $parts = explode('.', $slug);
            $action = end($parts);
            $area = $parts[1] ?? 'kams';

            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => ucfirst($action).' '.$area,
                    'group' => match ($area) {
                        'orders' => 'kams.stock',
                        'recruitment' => 'kams.members',
                        default => 'kams',
                    },
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $stockIds = DB::table('permissions')
            ->whereIn('slug', array_keys($this->createSlugs))
            ->pluck('id');

        if ($stockIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $stockIds)->delete();
            DB::table('permissions')->whereIn('id', $stockIds)->delete();
        }
    }
};
