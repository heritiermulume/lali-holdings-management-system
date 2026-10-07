<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private array $backfill = [
        'kams.members.statistics.view' => ['kams.members.view'],
        'kams.recruitment.reports.view' => ['kams.recruitment.view'],
        'kams.recruitment.print' => ['kams.recruitment.view', 'kams.members.print'],
        'kams.recruitment.preview' => ['kams.recruitment.view', 'kams.members.preview'],
        'kams.recruitment.interview.update' => ['kams.recruitment.update', 'kams.recruitment.create'],
        'kams.recruitment.observation.update' => ['kams.recruitment.update', 'kams.recruitment.create'],
        'kams.stock.statistics.view' => ['kams.stock.view', 'kams.products.view'],
        'kams.stock.reports.view' => ['kams.stock.view'],
        'kams.stock.journal.view' => ['kams.stock.view'],
        'kams.stock.print' => ['kams.stock.view', 'kams.products.view'],
        'kams.stock.preview' => ['kams.stock.view', 'kams.products.view'],
    ];

    /**
     * @var array<string, array{name: string, group: string}>
     */
    private array $definitions = [
        'kams.members.statistics.view' => ['name' => 'View members statistics', 'group' => 'kams.members'],
        'kams.recruitment.print' => ['name' => 'Print recruitment', 'group' => 'kams.recruitment'],
        'kams.recruitment.preview' => ['name' => 'Preview recruitment', 'group' => 'kams.recruitment'],
        'kams.recruitment.reports.view' => ['name' => 'View recruitment reports', 'group' => 'kams.recruitment'],
        'kams.recruitment.interview.update' => ['name' => 'Record recruitment interviews', 'group' => 'kams.recruitment'],
        'kams.recruitment.observation.update' => ['name' => 'Record recruitment observations', 'group' => 'kams.recruitment'],
        'kams.stock.print' => ['name' => 'Print stock', 'group' => 'kams.stock'],
        'kams.stock.preview' => ['name' => 'Preview stock', 'group' => 'kams.stock'],
        'kams.stock.statistics.view' => ['name' => 'View stock statistics', 'group' => 'kams.stock'],
        'kams.stock.reports.view' => ['name' => 'View stock reports', 'group' => 'kams.stock'],
        'kams.stock.journal.view' => ['name' => 'View stock journal', 'group' => 'kams.stock'],
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
