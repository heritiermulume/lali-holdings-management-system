<?php

use App\Models\Activity;
use App\Models\Company;
use App\Models\Office;
use App\Models\Permission;
use App\Models\SystemModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $slugs = [
            'kibiti-agribusiness',
            'properties-magomeni',
            'agribusiness-park-kisegese',
        ];
        $codes = ['KAP', 'LPM', 'LAK'];

        $moduleIds = SystemModule::query()->whereIn('slug', $slugs)->pluck('id');
        $companyIds = Company::query()->whereIn('code', $codes)->pluck('id');

        if ($companyIds->isNotEmpty()) {
            $officeIds = Office::query()->whereIn('company_id', $companyIds)->pluck('id');
            DB::table('users')->whereIn('primary_company_id', $companyIds)->update(['primary_company_id' => null]);
            if ($officeIds->isNotEmpty()) {
                DB::table('users')->whereIn('primary_office_id', $officeIds)->update(['primary_office_id' => null]);
            }
            Activity::query()->whereIn('company_id', $companyIds)->delete();
            Office::query()->whereIn('company_id', $companyIds)->delete();
            DB::table('company_module')->whereIn('company_id', $companyIds)->delete();
            Company::query()->whereIn('id', $companyIds)->delete();
        }

        if ($moduleIds->isNotEmpty()) {
            DB::table('company_module')->whereIn('system_module_id', $moduleIds)->delete();
            SystemModule::query()->whereIn('id', $moduleIds)->delete();
        }

        DB::table('settings')->whereIn('group', $slugs)->delete();
        Permission::query()->whereIn('group', $slugs)->delete();
    }

    public function down(): void
    {
        // Retired modules are not restored.
    }
};
