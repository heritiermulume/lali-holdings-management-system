<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private array $rename = [
        'kams.recruitment.view' => 'kams.recruitment.applications.view',
        'kams.members.view' => 'kams.members.agents.view',
        'kams.stock.view' => 'kams.stock.assignments.view',
    ];

    /** @var array<string, string> */
    private array $moveToReports = [
        'kams.recruitment.print' => 'kams.recruitment.reports.print',
        'kams.recruitment.preview' => 'kams.recruitment.reports.preview',
    ];

    /** @var array<string, string> */
    private array $moveStockPrint = [
        'kams.stock.print' => 'kams.stock.reports.print',
        'kams.stock.preview' => 'kams.stock.reports.preview',
    ];

    public function up(): void
    {
        foreach ($this->rename as $from => $to) {
            $this->renameSlug($from, $to);
        }

        foreach ($this->moveToReports as $from => $to) {
            $this->renameSlug($from, $to);
        }

        foreach ($this->moveStockPrint as $from => $to) {
            $this->renameSlug($from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->moveStockPrint, true) as $from => $to) {
            $this->renameSlug($to, $from);
        }
        foreach (array_reverse($this->moveToReports, true) as $from => $to) {
            $this->renameSlug($to, $from);
        }
        foreach (array_reverse($this->rename, true) as $from => $to) {
            $this->renameSlug($to, $from);
        }
    }

    private function renameSlug(string $from, string $to): void
    {
        $fromRow = DB::table('permissions')->where('slug', $from)->first();
        if (! $fromRow) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $to],
                [
                    'name' => ucfirst(str_replace('.', ' ', substr($to, strlen('kams.')))),
                    'group' => $this->groupFor($to),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            return;
        }

        $existingTo = DB::table('permissions')->where('slug', $to)->value('id');
        if ($existingTo) {
            DB::table('permission_role')
                ->where('permission_id', $fromRow->id)
                ->update(['permission_id' => $existingTo]);
            DB::table('permissions')->where('id', $fromRow->id)->delete();

            return;
        }

        DB::table('permissions')->where('id', $fromRow->id)->update([
            'slug' => $to,
            'name' => $fromRow->name,
            'group' => $this->groupFor($to),
            'updated_at' => now(),
        ]);
    }

    private function groupFor(string $slug): string
    {
        return match (true) {
            str_starts_with($slug, 'kams.finance.') => 'kams.finance',
            str_starts_with($slug, 'kams.recruitment.') => 'kams.recruitment',
            str_starts_with($slug, 'kams.members.') => 'kams.members',
            str_starts_with($slug, 'kams.stock.') => 'kams.stock',
            str_starts_with($slug, 'kams.clients') || str_starts_with($slug, 'kams.suppliers') => 'kams.clients',
            default => 'kams',
        };
    }

    /** @param list<string> $sourceSlugs */
    private function backfillRole(string $targetSlug, array $sourceSlugs): void
    {
        $targetId = DB::table('permissions')->where('slug', $targetSlug)->value('id');
        if (! $targetId) {
            return;
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
};
