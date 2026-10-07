<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $slugs = [
        'kams.view',
        'kams.create',
        'kams.update',
        'kams.delete',
        'kams.approve',
        'kams.export',
    ];

    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('slug', $this->slugs)
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    public function down(): void
    {
        foreach ($this->slugs as $slug) {
            $action = substr($slug, strlen('kams.'));
            DB::table('permissions')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => ucfirst($action).' kams',
                    'group' => 'kams',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
};
