<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $applicationActionSlugs = [
        'kams.recruitment.create',
        'kams.recruitment.update',
        'kams.recruitment.delete',
        'kams.recruitment.interview.update',
        'kams.recruitment.observation.update',
    ];

    public function up(): void
    {
        $applicationsViewId = DB::table('permissions')->where('slug', 'kams.recruitment.applications.view')->value('id');
        $reportsViewId = DB::table('permissions')->where('slug', 'kams.recruitment.reports.view')->value('id');
        if (! $applicationsViewId || ! $reportsViewId) {
            return;
        }

        $actionIds = DB::table('permissions')->whereIn('slug', $this->applicationActionSlugs)->pluck('id');

        $roleIdsWithReports = DB::table('permission_role')
            ->where('permission_id', $reportsViewId)
            ->pluck('role_id');

        foreach ($roleIdsWithReports as $roleId) {
            $hasApplicationsView = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $applicationsViewId)
                ->exists();
            if (! $hasApplicationsView) {
                continue;
            }

            $hasApplicationAction = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $actionIds)
                ->exists();

            if (! $hasApplicationAction) {
                DB::table('permission_role')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $applicationsViewId)
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        // Irreversible cleanup of mistaken cross-tab grants.
    }
};
