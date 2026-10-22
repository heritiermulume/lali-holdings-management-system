<?php

use App\Models\Activity;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Office;
use App\Models\SystemModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            [
                'slug' => 'machinery-fleet',
                'name' => 'Lali Machinery & Fleet',
                'name_fr' => 'Lali Machinery & Fleet',
                'description' => 'Machinery pool, fleet operations and maintenance.',
                'description_fr' => 'Parc machines, flotte et maintenance.',
                'status' => 'in_development',
                'sort_order' => 6,
                'icon' => 'wrench',
                'company' => [
                    'code' => 'LMF',
                    'name' => 'Lali Machinery & Fleet',
                    'legal_name' => 'Lali Machinery & Fleet SARL',
                    'email' => 'fleet@laliholdings.com',
                    'phone' => '+243 800 000 013',
                    'address' => 'Kinshasa, RDC',
                    'currency_code' => 'USD',
                    'description' => 'Machinery pool and fleet operations',
                    'geo' => 'drc',
                    'office_name' => 'Machinery & Fleet HQ',
                    'office_address' => 'Gombe, Kinshasa',
                    'activity' => 'Fleet operations',
                ],
            ],
            [
                'slug' => 'kibiti-agribusiness',
                'name' => 'Kibiti Agribusiness Project',
                'name_fr' => 'Kibiti Agribusiness Project',
                'description' => 'Agribusiness operations and farm management in Kibiti.',
                'description_fr' => 'Opérations agribusiness et gestion agricole à Kibiti.',
                'status' => 'in_development',
                'sort_order' => 7,
                'icon' => 'sprout',
                'company' => [
                    'code' => 'KAP',
                    'name' => 'Kibiti Agribusiness Project',
                    'legal_name' => 'Kibiti Agribusiness Project Ltd',
                    'email' => 'kibiti@laliholdings.com',
                    'phone' => '+255 700 000 003',
                    'address' => 'Kibiti, Tanzania',
                    'currency_code' => 'TZS',
                    'description' => 'Agribusiness project in Kibiti',
                    'geo' => 'tz',
                    'office_name' => 'Kibiti Project HQ',
                    'office_address' => 'Kibiti, Tanzania',
                    'activity' => 'Agribusiness',
                ],
            ],
            [
                'slug' => 'properties-magomeni',
                'name' => 'Lali Properties / Magomeni',
                'name_fr' => 'Lali Properties / Magomeni',
                'description' => 'Property and rental management for Magomeni.',
                'description_fr' => 'Gestion immobilière et locative à Magomeni.',
                'status' => 'in_development',
                'sort_order' => 8,
                'icon' => 'home',
                'company' => [
                    'code' => 'LPM',
                    'name' => 'Lali Properties / Magomeni',
                    'legal_name' => 'Lali Properties Magomeni Ltd',
                    'email' => 'magomeni@laliholdings.com',
                    'phone' => '+255 700 000 004',
                    'address' => 'Magomeni, Tanzania',
                    'currency_code' => 'TZS',
                    'description' => 'Property management in Magomeni',
                    'geo' => 'tz',
                    'office_name' => 'Magomeni Properties HQ',
                    'office_address' => 'Magomeni, Tanzania',
                    'activity' => 'Property management',
                ],
            ],
            [
                'slug' => 'agribusiness-park-kisegese',
                'name' => 'Lali Agribusiness Park - Kisegese',
                'name_fr' => 'Lali Agribusiness Park - Kisegese',
                'description' => 'Agribusiness park operations and tenancy at Kisegese.',
                'description_fr' => 'Parc agribusiness et gestion des occupants à Kisegese.',
                'status' => 'in_development',
                'sort_order' => 9,
                'icon' => 'trees',
                'company' => [
                    'code' => 'LAK',
                    'name' => 'Lali Agribusiness Park - Kisegese',
                    'legal_name' => 'Lali Agribusiness Park Kisegese Ltd',
                    'email' => 'kisegese@laliholdings.com',
                    'phone' => '+255 700 000 005',
                    'address' => 'Kisegese, Tanzania',
                    'currency_code' => 'TZS',
                    'description' => 'Agribusiness park at Kisegese',
                    'geo' => 'tz',
                    'office_name' => 'Kisegese Park HQ',
                    'office_address' => 'Kisegese, Tanzania',
                    'activity' => 'Agribusiness park',
                ],
            ],
        ];

        $holdings = Company::query()->where('code', 'LALI')->first();
        $drc = Country::query()->where('iso_code', 'CD')->first();
        $tz = Country::query()->where('iso_code', 'TZ')->first();
        $fallbackCountry = $drc ?? Country::query()->orderBy('id')->first();
        $kinshasa = City::query()->where('name', 'Kinshasa')->first();
        $zanzibarCity = City::query()->where('name', 'Zanzibar City')->first()
            ?? City::query()->whereHas('country', fn ($q) => $q->where('iso_code', 'TZ'))->first();
        $fallbackCity = $kinshasa
            ?? City::query()->where('country_id', $fallbackCountry?->id)->orderBy('id')->first()
            ?? City::query()->orderBy('id')->first();

        foreach ($definitions as $definition) {
            $companyDef = $definition['company'];
            unset($definition['company']);

            $module = SystemModule::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + ['version' => '0.1.0'],
            );

            if (! $fallbackCountry) {
                continue;
            }

            $preferTz = $companyDef['geo'] === 'tz';
            $country = ($preferTz && $tz) ? $tz : ($fallbackCountry);
            $city = ($preferTz && $zanzibarCity) ? $zanzibarCity : $fallbackCity;

            $company = Company::query()->updateOrCreate(['code' => $companyDef['code']], [
                'country_id' => $country->id,
                'city_id' => $city?->id,
                'system_module_id' => $module->id,
                'name' => $companyDef['name'],
                'legal_name' => $companyDef['legal_name'],
                'email' => $companyDef['email'],
                'phone' => $companyDef['phone'],
                'address' => $companyDef['address'],
                'currency_code' => $companyDef['currency_code'],
                'description' => $companyDef['description'],
            ]);

            $company->modules()->syncWithoutDetaching([
                $module->id => ['is_enabled' => true],
            ]);

            if ($holdings) {
                $holdings->modules()->syncWithoutDetaching([
                    $module->id => ['is_enabled' => true],
                ]);
            }

            Office::query()->updateOrCreate(['company_id' => $company->id, 'code' => 'HQ'], [
                'country_id' => $country?->id,
                'city_id' => $city?->id,
                'name' => $companyDef['office_name'],
                'type' => 'headquarters',
                'address' => $companyDef['office_address'],
            ]);

            Activity::query()->updateOrCreate(
                ['company_id' => $company->id, 'name' => $companyDef['activity']],
            );
        }
    }

    public function down(): void
    {
        $slugs = [
            'machinery-fleet',
            'kibiti-agribusiness',
            'properties-magomeni',
            'agribusiness-park-kisegese',
        ];
        $codes = ['LMF', 'KAP', 'LPM', 'LAK'];

        $moduleIds = SystemModule::query()->whereIn('slug', $slugs)->pluck('id');
        $companyIds = Company::query()->whereIn('code', $codes)->pluck('id');

        if ($companyIds->isNotEmpty()) {
            Activity::query()->whereIn('company_id', $companyIds)->delete();
            Office::query()->whereIn('company_id', $companyIds)->delete();
            DB::table('company_module')->whereIn('company_id', $companyIds)->delete();
            Company::query()->whereIn('id', $companyIds)->delete();
        }

        if ($moduleIds->isNotEmpty()) {
            DB::table('company_module')->whereIn('system_module_id', $moduleIds)->delete();
            SystemModule::query()->whereIn('id', $moduleIds)->delete();
        }
    }
};
