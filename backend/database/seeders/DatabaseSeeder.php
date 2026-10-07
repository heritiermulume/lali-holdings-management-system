<?php

namespace Database\Seeders;

use App\Core\Support\PermissionCatalog;
use App\Core\Support\RoleCatalog;
use App\Models\Activity;
use App\Models\AppNotification;
use App\Models\City;
use App\Models\Commune;
use App\Models\Company;
use App\Models\Country;
use App\Models\Neighborhood;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Province;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SystemModule;
use App\Models\Task;
use App\Models\User;
use App\Modules\Kams\Models\KamsCandidate;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsOrderItem;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Models\KamsStockTransfer;
use App\Modules\Kams\Services\KamsAccountProvisioner;
use App\Modules\Kams\Services\MemberMigrationService;
use App\Modules\Kams\Services\RevenueRuleService;
use App\Modules\Kams\Services\StockInventory;
use App\Modules\Kams\Support\PersonName;
use App\Support\SettingsCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissionsAndRoles();
        $this->seedModules();
        $this->seedSettings();
        $this->seedSuperAdmin();
        app(RevenueRuleService::class)->ensureAll();

        // Optional demo dataset (companies, offices, KAMS sample data, extra users).
        if (filter_var(env('SEED_DEMO', false), FILTER_VALIDATE_BOOLEAN)) {
            $geography = $this->seedGeography();
            $modules = $this->seedModules();
            $companies = $this->seedCompanies($geography, $modules);
            $this->linkModuleRoleOffices();
            $admin = $this->seedDemoUsers($companies);
            $this->seedKams($companies['king'], $companies['gomaOffice']);
            $this->seedKamsRecruitment($companies['king']);
            $this->seedKamsStock($companies['king']);
            $this->seedKamsAccounts();
            $this->seedInbox($admin, $companies['king']);
        }
    }

    private function seedPermissionsAndRoles(): void
    {
        $permissions = collect(PermissionCatalog::core())
            ->concat(PermissionCatalog::forKams())
            ->concat(PermissionCatalog::forModule('heavy-equipment'))
            ->concat(PermissionCatalog::forModule('real-estate'))
            ->concat(PermissionCatalog::forModule('rice-mills'))
            ->concat(PermissionCatalog::forModule('lali-tv'));

        $permissionIds = $permissions->mapWithKeys(function (array $permission) {
            $model = Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                ['name' => $permission['name'], 'group' => $permission['group']]
            );

            return [$permission['slug'] => $model->id];
        });

        $roles = RoleCatalog::all();

        foreach ($roles as $roleData) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['name'],
                    'level' => $roleData['level'],
                    'module_slug' => $roleData['module_slug'],
                    'is_system' => true,
                ]
            );

            $slugs = RoleCatalog::permissionSlugsFor($roleData['slug']);
            if ($slugs === null) {
                $role->permissions()->sync($permissionIds->values());
            } else {
                $role->permissions()->sync($permissionIds->only($slugs)->values());
            }
        }
    }

    private function linkModuleRoleOffices(): void
    {
        $kamsOfficeIds = Office::query()
            ->whereHas('company.module', fn ($module) => $module->where('slug', 'kams'))
            ->pluck('id');

        if ($kamsOfficeIds->isEmpty()) {
            return;
        }

        Role::query()
            ->where('module_slug', 'kams')
            ->whereNotIn('slug', RoleCatalog::fullAccessSlugs())
            ->get()
            ->each(function (Role $role) use ($kamsOfficeIds) {
                $role->offices()->syncWithoutDetaching($kamsOfficeIds);
            });
    }

    private function seedGeography(): array
    {
        $drc = Country::query()->updateOrCreate(['iso_code' => 'CD'], [
            'name' => 'Democratic Republic of the Congo',
            'name_fr' => 'République démocratique du Congo',
            'phone_code' => '+243',
            'currency_code' => 'USD',
            'currency_symbol' => '$',
            'tax_name' => 'TVA',
            'tax_rate' => 16,
            'latitude' => 0,
            'longitude' => 25,
        ]);

        $tz = Country::query()->updateOrCreate(['iso_code' => 'TZ'], [
            'name' => 'Tanzania',
            'name_fr' => 'Tanzanie',
            'phone_code' => '+255',
            'currency_code' => 'TZS',
            'currency_symbol' => 'TSh',
            'tax_name' => 'VAT',
            'tax_rate' => 18,
            'latitude' => -6.3690,
            'longitude' => 34.8888,
        ]);

        $kinshasaProvince = Province::query()->updateOrCreate(['country_id' => $drc->id, 'code' => 'KN'], [
            'name' => 'Kinshasa',
            'name_fr' => 'Kinshasa',
        ]);
        $northKivu = Province::query()->updateOrCreate(['country_id' => $drc->id, 'code' => 'NK'], [
            'name' => 'North Kivu',
            'name_fr' => 'Nord-Kivu',
        ]);
        $zanzibar = Province::query()->updateOrCreate(['country_id' => $tz->id, 'code' => 'ZN'], [
            'name' => 'Zanzibar',
            'name_fr' => 'Zanzibar',
        ]);

        $kinshasa = City::query()->updateOrCreate(['country_id' => $drc->id, 'name' => 'Kinshasa'], [
            'name_fr' => 'Kinshasa',
            'province_id' => $kinshasaProvince->id,
        ]);
        $goma = City::query()->updateOrCreate(['country_id' => $drc->id, 'name' => 'Goma'], [
            'name_fr' => 'Goma',
            'province_id' => $northKivu->id,
        ]);
        $zanzibarCity = City::query()->updateOrCreate(['country_id' => $tz->id, 'name' => 'Zanzibar City'], [
            'name_fr' => 'Zanzibar',
            'province_id' => $zanzibar->id,
        ]);

        $gombe = Commune::query()->updateOrCreate(['city_id' => $kinshasa->id, 'name' => 'Gombe'], [
            'name_fr' => 'Gombe',
        ]);
        $karisimbi = Commune::query()->updateOrCreate(['city_id' => $goma->id, 'name' => 'Karisimbi'], [
            'name_fr' => 'Karisimbi',
        ]);
        $gomaCommune = Commune::query()->updateOrCreate(['city_id' => $goma->id, 'name' => 'Goma'], [
            'name_fr' => 'Goma',
        ]);
        $miembeni = Commune::query()->updateOrCreate(['city_id' => $zanzibarCity->id, 'name' => 'Miembeni'], [
            'name_fr' => 'Miembeni',
        ]);

        $batetela = Neighborhood::query()->updateOrCreate(['commune_id' => $gombe->id, 'name' => 'Batetela'], [
            'name_fr' => 'Batetela',
        ]);
        $himbi = Neighborhood::query()->updateOrCreate(['commune_id' => $karisimbi->id, 'name' => 'Himbi'], [
            'name_fr' => 'Himbi',
        ]);
        $keshero = Neighborhood::query()->updateOrCreate(['commune_id' => $gomaCommune->id, 'name' => 'Keshero'], [
            'name_fr' => 'Keshero',
        ]);
        $miembeniQuarter = Neighborhood::query()->updateOrCreate(['commune_id' => $miembeni->id, 'name' => 'Miembeni'], [
            'name_fr' => 'Miembeni',
        ]);

        return compact(
            'drc', 'tz', 'kinshasaProvince', 'northKivu', 'zanzibar',
            'kinshasa', 'goma', 'zanzibarCity',
            'gombe', 'karisimbi', 'gomaCommune', 'miembeni',
            'batetela', 'himbi', 'keshero', 'miembeniQuarter'
        );
    }

    private function seedModules(): array
    {
        $definitions = [
            ['slug' => 'kams', 'name' => 'King Attitude Management System', 'name_fr' => 'King Attitude Management System', 'status' => 'active', 'sort_order' => 1, 'icon' => 'crown'],
            ['slug' => 'heavy-equipment', 'name' => 'LALI Heavy Equipment', 'name_fr' => 'LALI Heavy Equipment', 'status' => 'in_development', 'sort_order' => 2, 'icon' => 'truck'],
            ['slug' => 'real-estate', 'name' => 'LALI Apartments / Real Estate', 'name_fr' => 'LALI Apartments / Immobilier', 'status' => 'coming_soon', 'sort_order' => 3, 'icon' => 'building'],
            ['slug' => 'rice-mills', 'name' => 'LALI Miembeni Rice Mills', 'name_fr' => 'LALI Miembeni Rice Mills', 'status' => 'coming_soon', 'sort_order' => 4, 'icon' => 'wheat'],
            ['slug' => 'lali-tv', 'name' => 'LALI TV', 'name_fr' => 'LALI TV', 'status' => 'inactive', 'sort_order' => 5, 'icon' => 'tv'],
        ];

        $modules = [];
        foreach ($definitions as $module) {
            $modules[$module['slug']] = SystemModule::query()->updateOrCreate(['slug' => $module['slug']], $module + [
                'description' => $module['name'],
                'description_fr' => $module['name_fr'],
                'version' => $module['slug'] === 'kams' ? '1.0.0' : '0.1.0',
            ]);
        }

        return $modules;
    }

    private function seedCompanies(array $geo, array $modules): array
    {
        $holdings = Company::query()->updateOrCreate(['code' => 'LALI'], [
            'country_id' => $geo['drc']->id,
            'city_id' => $geo['kinshasa']->id,
            'system_module_id' => null,
            'name' => 'LALI Holdings',
            'legal_name' => 'LALI Holdings Group',
            'email' => 'contact@laliholdings.com',
            'phone' => '+243 800 000 000',
            'address' => 'Kinshasa, RDC',
            'currency_code' => 'USD',
            'description' => 'Holding group headquarters',
        ]);

        $king = Company::query()->updateOrCreate(['code' => 'KAMS'], [
            'country_id' => $geo['drc']->id,
            'city_id' => $geo['goma']->id,
            'system_module_id' => $modules['kams']->id,
            'name' => 'King Attitude Traders',
            'legal_name' => 'King Attitude Traders SARL',
            'email' => 'hello@kingattitude.com',
            'phone' => '+243 900 000 001',
            'address' => 'Goma, Nord-Kivu',
            'currency_code' => 'USD',
            'description' => 'Fashion and lifestyle brand of LALI Holdings',
        ]);

        $heavy = Company::query()->updateOrCreate(['code' => 'LHE'], [
            'country_id' => $geo['drc']->id,
            'city_id' => $geo['kinshasa']->id,
            'system_module_id' => $modules['heavy-equipment']->id,
            'name' => 'LALI Heavy Equipment',
            'legal_name' => 'LALI Heavy Equipment SARL',
            'email' => 'equipment@laliholdings.com',
            'phone' => '+243 800 000 010',
            'address' => 'Kinshasa, RDC',
            'currency_code' => 'USD',
            'description' => 'Heavy equipment rental and operations',
        ]);

        $estate = Company::query()->updateOrCreate(['code' => 'LRE'], [
            'country_id' => $geo['drc']->id,
            'city_id' => $geo['kinshasa']->id,
            'system_module_id' => $modules['real-estate']->id,
            'name' => 'LALI Apartments',
            'legal_name' => 'LALI Real Estate SARL',
            'email' => 'immobilier@laliholdings.com',
            'phone' => '+243 800 000 011',
            'address' => 'Kinshasa, RDC',
            'currency_code' => 'USD',
            'description' => 'Real estate and apartment management',
        ]);

        $rice = Company::query()->updateOrCreate(['code' => 'LMRM'], [
            'country_id' => $geo['tz']->id,
            'city_id' => $geo['zanzibarCity']->id,
            'system_module_id' => $modules['rice-mills']->id,
            'name' => 'LALI Miembeni Rice Mills',
            'legal_name' => 'LALI Miembeni Rice Mills Ltd',
            'email' => 'rice@laliholdings.com',
            'phone' => '+255 700 000 002',
            'address' => 'Miembeni, Zanzibar',
            'currency_code' => 'TZS',
            'description' => 'Rice milling and distribution',
        ]);

        $tv = Company::query()->updateOrCreate(['code' => 'LTV'], [
            'country_id' => $geo['drc']->id,
            'city_id' => $geo['kinshasa']->id,
            'system_module_id' => $modules['lali-tv']->id,
            'name' => 'LALI TV',
            'legal_name' => 'LALI TV SARL',
            'email' => 'tv@laliholdings.com',
            'phone' => '+243 800 000 012',
            'address' => 'Kinshasa, RDC',
            'currency_code' => 'USD',
            'description' => 'Media and broadcasting',
        ]);

        $businessCompanies = [$king, $heavy, $estate, $rice, $tv];
        foreach ($businessCompanies as $company) {
            if ($company->system_module_id) {
                $company->modules()->syncWithoutDetaching([
                    $company->system_module_id => ['is_enabled' => true],
                ]);
                $holdings->modules()->syncWithoutDetaching([
                    $company->system_module_id => ['is_enabled' => true],
                ]);
            }
        }

        $hq = Office::query()->updateOrCreate(['company_id' => $holdings->id, 'code' => 'HQ'], [
            'country_id' => $geo['drc']->id,
            'province_id' => $geo['kinshasaProvince']->id,
            'city_id' => $geo['kinshasa']->id,
            'commune_id' => $geo['gombe']->id,
            'neighborhood_id' => $geo['batetela']->id,
            'name' => 'Group Headquarters',
            'type' => 'headquarters',
            'address' => 'Gombe, Kinshasa',
        ]);

        $gomaOffice = Office::query()->updateOrCreate(['company_id' => $king->id, 'code' => 'GMA'], [
            'country_id' => $geo['drc']->id,
            'province_id' => $geo['northKivu']->id,
            'city_id' => $geo['goma']->id,
            'commune_id' => $geo['karisimbi']->id,
            'neighborhood_id' => $geo['himbi']->id,
            'name' => 'KAMS Goma',
            'type' => 'headquarters',
            'address' => 'Himbi, Goma',
        ]);

        Office::query()->updateOrCreate(['company_id' => $king->id, 'code' => 'KIN'], [
            'country_id' => $geo['drc']->id,
            'province_id' => $geo['kinshasaProvince']->id,
            'city_id' => $geo['kinshasa']->id,
            'commune_id' => $geo['gombe']->id,
            'neighborhood_id' => $geo['batetela']->id,
            'name' => 'KAMS Kinshasa',
            'type' => 'branch',
            'address' => 'Gombe, Kinshasa',
        ]);

        Office::query()->updateOrCreate(['company_id' => $heavy->id, 'code' => 'HQ'], [
            'country_id' => $geo['drc']->id,
            'province_id' => $geo['kinshasaProvince']->id,
            'city_id' => $geo['kinshasa']->id,
            'commune_id' => $geo['gombe']->id,
            'neighborhood_id' => $geo['batetela']->id,
            'name' => 'Heavy Equipment HQ',
            'type' => 'headquarters',
            'address' => 'Gombe, Kinshasa',
        ]);

        Office::query()->updateOrCreate(['company_id' => $estate->id, 'code' => 'HQ'], [
            'country_id' => $geo['drc']->id,
            'province_id' => $geo['kinshasaProvince']->id,
            'city_id' => $geo['kinshasa']->id,
            'commune_id' => $geo['gombe']->id,
            'neighborhood_id' => $geo['batetela']->id,
            'name' => 'Apartments HQ',
            'type' => 'headquarters',
            'address' => 'Gombe, Kinshasa',
        ]);

        Office::query()->updateOrCreate(['company_id' => $rice->id, 'code' => 'MMB'], [
            'country_id' => $geo['tz']->id,
            'province_id' => $geo['zanzibar']->id,
            'city_id' => $geo['zanzibarCity']->id,
            'commune_id' => $geo['miembeni']->id,
            'neighborhood_id' => $geo['miembeniQuarter']->id,
            'name' => 'Miembeni Mill',
            'type' => 'headquarters',
            'address' => 'Miembeni, Zanzibar',
        ]);

        Office::query()->updateOrCreate(['company_id' => $tv->id, 'code' => 'HQ'], [
            'country_id' => $geo['drc']->id,
            'province_id' => $geo['kinshasaProvince']->id,
            'city_id' => $geo['kinshasa']->id,
            'commune_id' => $geo['gombe']->id,
            'neighborhood_id' => $geo['batetela']->id,
            'name' => 'LALI TV Studio',
            'type' => 'headquarters',
            'address' => 'Gombe, Kinshasa',
        ]);

        Activity::query()->updateOrCreate(['company_id' => $king->id, 'name' => 'Retail fashion']);
        Activity::query()->updateOrCreate(['company_id' => $rice->id, 'name' => 'Rice milling']);

        return compact('holdings', 'king', 'heavy', 'estate', 'rice', 'tv', 'hq', 'gomaOffice');
    }

    private function seedSuperAdmin(): User
    {
        $admin = User::query()->updateOrCreate(['email' => 'heritiermulume@gmail.com'], [
            'first_name' => 'Héritier',
            'last_name' => 'Mulume',
            'phone' => '+243 824 449 218',
            'locale' => 'fr',
            'timezone' => 'Africa/Kinshasa',
            'is_active' => true,
            'primary_company_id' => null,
            'primary_office_id' => null,
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);
        $admin->roles()->detach();
        $admin->roles()->attach(Role::query()->where('slug', 'super_administrator')->value('id'), ['company_id' => null]);
        $admin->scopes()->where('scope_type', '!=', 'group')->delete();
        $admin->scopes()->updateOrCreate(['scope_type' => 'group'], ['scope_id' => null]);

        return $admin;
    }

    private function seedDemoUsers(array $companies): User
    {
        $admin = $this->seedSuperAdmin();
        $admin->forceFill([
            'primary_company_id' => $companies['holdings']->id,
            'primary_office_id' => $companies['hq']->id,
        ])->save();

        $companyAdmin = User::query()->updateOrCreate(['email' => 'kevin.m@example.com'], [
            'first_name' => 'Amina',
            'last_name' => 'King',
            'phone' => '+243 900 111 333',
            'locale' => 'fr',
            'is_active' => true,
            'primary_company_id' => $companies['king']->id,
            'primary_office_id' => $companies['gomaOffice']->id,
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);
        $companyAdmin->roles()->detach();
        $companyAdmin->roles()->attach(Role::query()->where('slug', 'manager')->value('id'), ['company_id' => $companies['king']->id]);
        $companyAdmin->scopes()->updateOrCreate(
            ['scope_type' => 'company', 'scope_id' => $companies['king']->id],
            []
        );
        $companyAdmin->scopes()->updateOrCreate(
            ['scope_type' => 'module', 'scope_id' => $companies['king']->system_module_id],
            []
        );
        $companyAdmin->scopes()->updateOrCreate(
            ['scope_type' => 'office', 'scope_id' => $companies['gomaOffice']->id],
            []
        );

        return $admin;
    }

    private function seedSettings(): void
    {
        $settings = [
            ['group' => 'general', 'key' => 'group_name', 'value' => 'LALI Holdings'],
            ['group' => 'general', 'key' => 'legal_name', 'value' => 'LALI Holdings SAS'],
            ['group' => 'general', 'key' => 'support_email', 'value' => 'samuel.w@example.com'],
            ['group' => 'general', 'key' => 'mail_from_address', 'value' => 'samuel.w@example.com'],
            ['group' => 'general', 'key' => 'mail_from_name', 'value' => 'LALI Holdings'],
            ['group' => 'general', 'key' => 'mail_host', 'value' => ''],
            ['group' => 'general', 'key' => 'mail_port', 'value' => '587', 'type' => 'number'],
            ['group' => 'general', 'key' => 'mail_username', 'value' => ''],
            ['group' => 'general', 'key' => 'mail_password', 'value' => ''],
            ['group' => 'general', 'key' => 'mail_encryption', 'value' => 'tls'],
            ['group' => 'general', 'key' => 'default_locale', 'value' => 'fr'],
            ['group' => 'general', 'key' => 'default_timezone', 'value' => 'Africa/Kinshasa'],
            ['group' => 'general', 'key' => 'date_format', 'value' => 'DD/MM/YYYY'],
            ['group' => 'general', 'key' => 'week_start', 'value' => '1'],
            ['group' => 'finance', 'key' => 'reporting_currency', 'value' => 'USD'],
            ['group' => 'finance', 'key' => 'decimal_places', 'value' => '2', 'type' => 'number'],
            ['group' => 'finance', 'key' => 'fiscal_year_start_month', 'value' => '1', 'type' => 'number'],
            ['group' => 'finance', 'key' => 'fx_spread_percent', 'value' => '1.5', 'type' => 'number'],
            ['group' => 'finance', 'key' => 'multi_currency', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'finance', 'key' => 'tax_name', 'value' => 'TVA'],
            ['group' => 'finance', 'key' => 'default_tax_rate', 'value' => '16', 'type' => 'number'],
            ['group' => 'security', 'key' => 'password_min_length', 'value' => '10', 'type' => 'number'],
            ['group' => 'security', 'key' => 'password_require_mixed', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'security', 'key' => 'session_lifetime_minutes', 'value' => '120', 'type' => 'number'],
            ['group' => 'security', 'key' => 'force_password_reset_days', 'value' => '90', 'type' => 'number'],
            ['group' => 'kams', 'key' => 'order_prefix', 'value' => 'KA'],
            ['group' => 'kams', 'key' => 'invoice_prefix', 'value' => 'KAF'],
            ['group' => 'kams', 'key' => 'stock_alert_threshold', 'value' => '5', 'type' => 'number'],
            ['group' => 'kams', 'key' => 'default_payment_terms_days', 'value' => '30', 'type' => 'number'],
            ['group' => 'kams', 'key' => 'allow_backorders', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'kams', 'key' => 'low_stock_notify', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'kams', 'key' => 'interview_hours', 'value' => '12:00-16:00'],
            ['group' => 'kams', 'key' => 'observation_hours', 'value' => '07:30-17:30'],
            ['group' => 'kams', 'key' => 'stock_loss_alert_percent', 'value' => '10', 'type' => 'number'],
            ['group' => 'kams', 'key' => 'default_member_rank', 'value' => 'Distributor'],
            ['group' => 'heavy-equipment', 'key' => 'default_rental_days', 'value' => '7', 'type' => 'number'],
            ['group' => 'heavy-equipment', 'key' => 'maintenance_interval_hours', 'value' => '250', 'type' => 'number'],
            ['group' => 'heavy-equipment', 'key' => 'require_operator_license', 'value' => '1', 'type' => 'boolean'],
            ['group' => 'real-estate', 'key' => 'default_lease_months', 'value' => '12', 'type' => 'number'],
            ['group' => 'real-estate', 'key' => 'occupancy_alert_percent', 'value' => '80', 'type' => 'number'],
            ['group' => 'real-estate', 'key' => 'late_rent_grace_days', 'value' => '5', 'type' => 'number'],
            ['group' => 'rice-mills', 'key' => 'standard_bag_kg', 'value' => '50', 'type' => 'number'],
            ['group' => 'rice-mills', 'key' => 'moisture_max_percent', 'value' => '14', 'type' => 'number'],
            ['group' => 'rice-mills', 'key' => 'mill_shift_hours', 'value' => '8', 'type' => 'number'],
            ['group' => 'lali-tv', 'key' => 'default_broadcast_tz', 'value' => 'Africa/Kinshasa'],
            ['group' => 'lali-tv', 'key' => 'max_upload_mb', 'value' => '512', 'type' => 'number'],
            ['group' => 'lali-tv', 'key' => 'content_review_required', 'value' => '1', 'type' => 'boolean'],
        ];

        foreach ($settings as $setting) {
            Setting::query()->updateOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type'] ?? 'string']
            );
        }

        SettingsCatalog::ensure();
    }

    private function seedKams(Company $company, Office $office): void
    {
        app(RevenueRuleService::class)->seedDefaults((int) $company->id);

        $clients = collect([
            ['name' => 'Boutique Horizon', 'email' => 'horizon@client.cd', 'city' => 'Goma', 'segment' => 'wholesale'],
            ['name' => 'Maison Kivu', 'email' => 'kivu@client.cd', 'city' => 'Goma', 'segment' => 'retail'],
            ['name' => 'Urban Attitude', 'email' => 'urban@client.cd', 'city' => 'Kinshasa', 'segment' => 'retail'],
        ])->map(fn ($client) => KamsClient::query()->updateOrCreate(
            ['company_id' => $company->id, 'email' => $client['email']],
            $client + ['status' => 'active', 'office_id' => $office->id]
        ));

        KamsDepot::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'DEP-GMA'],
            [
                'office_id' => $office->id,
                'name' => 'Dépôt Goma',
                'status' => 'active',
                'notes' => 'Entrepôt principal King Attitude Traders.',
            ]
        );

        $products = collect([
            ['sku' => 'KA-TEE-01', 'name' => 'King Attitude Tee', 'category' => 'Apparel', 'collection' => 'Core', 'price' => 35, 'field_price' => 35, 'manager_price' => 21, 'company_price' => 21, 'cost' => 21],
            ['sku' => 'KA-HD-02', 'name' => 'Crown Hoodie', 'category' => 'Apparel', 'collection' => 'Essentials', 'price' => 72, 'field_price' => 72, 'manager_price' => 43, 'company_price' => 43, 'cost' => 43],
            ['sku' => 'KA-CAP-03', 'name' => 'Signature Cap', 'category' => 'Accessories', 'collection' => 'Core', 'price' => 28, 'field_price' => 28, 'manager_price' => 17, 'company_price' => 17, 'cost' => 17],
            ['sku' => 'KA-BAG-04', 'name' => 'Attitude Tote', 'category' => 'Accessories', 'collection' => 'Limited', 'price' => 46, 'field_price' => 46, 'manager_price' => 28, 'company_price' => 28, 'cost' => 28],
            ['sku' => 'KA-DI-05', 'name' => 'DI', 'category' => 'Core', 'collection' => 'Daily', 'price' => 7500, 'field_price' => 7500, 'manager_price' => 4500, 'company_price' => 4500, 'cost' => 4500],
        ])->map(fn ($product) => KamsProduct::query()->updateOrCreate(
            ['company_id' => $company->id, 'sku' => $product['sku']],
            $product + ['status' => 'active', 'stock' => 0, 'avg_cost' => $product['cost']]
        ));

        if (! KamsOrder::query()->where('company_id', $company->id)->exists()) {
            $order = KamsOrder::query()->create([
                'company_id' => $company->id,
                'client_id' => $clients->first()->id,
                'reference' => 'KA-260911-DEMO',
                'status' => 'confirmed',
                'subtotal' => 3500,
                'tax' => 560,
                'total' => 4060,
                'ordered_at' => now()->subDays(4),
            ]);

            KamsOrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $products->first()->id,
                'quantity' => 100,
                'unit_price' => 35,
                'line_total' => 3500,
            ]);

            KamsOrder::query()->create([
                'company_id' => $company->id,
                'client_id' => $clients->last()->id,
                'reference' => 'KA-260908-URB',
                'status' => 'fulfilled',
                'subtotal' => 2160,
                'tax' => 345.6,
                'total' => 2505.6,
                'ordered_at' => now()->subDays(12),
            ]);
        }
    }

    private function seedKamsRecruitment(Company $company): void
    {
        $office = Office::query()->where('company_id', $company->id)->where('code', 'GMA')->first();

        if (! KamsCandidate::query()->where('company_id', $company->id)->exists()) {
            KamsCandidate::query()->create([
                'company_id' => $company->id,
                'office_id' => $office?->id,
                'candidate_id' => 'KA-C-'.now()->year.'-000001',
                ...PersonName::fromParts('Amina', 'Bahati'),
                'phone' => '+243 970 111 222',
                'address' => 'Himbi, Goma',
                'guardian_name' => 'Joseph Bahati',
                'guardian_phone' => '+243 970 111 223',
                'source' => 'qr_code',
                'status' => 'under_review',
                'applied_at' => now()->subDays(3),
            ]);
        }

        if (! KamsMember::query()->where('company_id', $company->id)->where('origin', 'migrated')->exists()) {
            app(MemberMigrationService::class)->import((int) $company->id, (int) User::query()->value('id'), 'excel', 'ATL existing register', [
                [
                    'member_code' => 'ATL-2019-0142',
                    'full_name' => 'Jean-Luc Mwamba',
                    'phone' => '+243 810 200 300',
                    'address' => 'Karisimbi, Goma',
                    'trainer_name' => 'Patrick K.',
                    'manager_name' => 'Sarah M.',
                    'office_code' => 'GMA',
                    'joined_on' => '2019-04-12',
                    'rank' => 'Distributor',
                    'status' => 'active',
                    'consignment_balance' => 420,
                    'distributor_savings' => 85,
                ],
            ]);
        }

        $goma = Office::query()->where('company_id', $company->id)->where('code', 'GMA')->first();
        $manager = KamsMember::query()->updateOrCreate(
            ['company_id' => $company->id, 'member_code' => 'ATL-MGR-0001'],
            [
                ...PersonName::fromParts('Sarah', 'Mukendi'),
                'office_id' => $goma?->id,
                'phone' => '+243 810 200 301',
                'rank' => 'Manager',
                'status' => 'active',
                'origin' => 'migrated',
                'joined_on' => '2018-01-10',
            ]
        );
        KamsMember::query()
            ->where('company_id', $company->id)
            ->where('member_code', 'ATL-2019-0142')
            ->update(['manager_member_id' => $manager->id, 'manager_name' => $manager->full_name]);

        $companyAdmin = User::query()->where('email', 'kevin.m@example.com')->first();
        if ($companyAdmin) {
            $manager->forceFill([
                'user_id' => $companyAdmin->id,
                'email' => $companyAdmin->email,
            ])->save();
        }
    }

    private function seedKamsStock(Company $company): void
    {
        $inventory = app(StockInventory::class);
        $inventory->ensureOfficeDepots($company->id);

        $northKivu = Province::query()->where('code', 'NK')->first();
        $kinshasa = Province::query()->where('code', 'KN')->first();
        $gomaOffice = Office::query()->where('company_id', $company->id)->where('code', 'GMA')->first();
        $kinOffice = Office::query()->where('company_id', $company->id)->where('code', 'KIN')->first();

        $northWarehouse = KamsDepot::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'ENT-NKV'],
            [
                'name' => 'Entrepôt Nord-Kivu',
                'kind' => 'warehouse',
                'office_id' => null,
                'province_id' => $northKivu?->id,
                'status' => 'active',
            ]
        );
        KamsDepot::query()->updateOrCreate(
            ['company_id' => $company->id, 'code' => 'ENT-KIN'],
            [
                'name' => 'Entrepôt Kinshasa',
                'kind' => 'warehouse',
                'office_id' => null,
                'province_id' => $kinshasa?->id,
                'status' => 'active',
            ]
        );

        $legacy = KamsDepot::query()->where('company_id', $company->id)->where('code', 'ENT-MAIN')->first();
        if ($legacy && $legacy->id !== $northWarehouse->id) {
            $legacy->load('balances.product');
            foreach ($legacy->balances as $balance) {
                $target = KamsStockBalance::query()->firstOrCreate(
                    ['depot_id' => $northWarehouse->id, 'product_id' => $balance->product_id, 'condition' => $balance->condition ?: 'available'],
                    ['company_id' => $company->id, 'quantity' => 0]
                );
                $target->quantity = (int) $target->quantity + (int) $balance->quantity;
                $target->save();
                $balance->delete();
            }
            $legacy->delete();
        }

        $warehouses = KamsDepot::query()
            ->where('company_id', $company->id)
            ->where('kind', 'warehouse')
            ->get();
        $adminId = User::query()->orderBy('id')->value('id');

        // Initial warehouse stock lives in balances (not the denormalized products.stock column).
        $initialWarehouseStock = [
            'KA-TEE-01' => 180,
            'KA-HD-02' => 90,
            'KA-CAP-03' => 140,
            'KA-BAG-04' => 60,
            'KA-DI-05' => 0,
        ];
        foreach ($initialWarehouseStock as $sku => $qty) {
            $seedProduct = KamsProduct::query()->where('company_id', $company->id)->where('sku', $sku)->first();
            if (! $seedProduct) {
                continue;
            }
            foreach ($warehouses as $warehouse) {
                KamsStockBalance::query()->firstOrCreate(
                    ['depot_id' => $warehouse->id, 'product_id' => $seedProduct->id, 'condition' => 'available'],
                    ['company_id' => $company->id, 'quantity' => 0]
                );
            }
            $onHand = (int) KamsStockBalance::query()->where('product_id', $seedProduct->id)->sum('quantity');
            if ($qty > $onHand && $adminId) {
                $inventory->receive($northWarehouse, $seedProduct, $qty - $onHand, (int) $adminId, 'Stock initial entrepôt');
            }
        }

        $product = KamsProduct::query()->where('company_id', $company->id)->where('sku', 'KA-TEE-01')->first();
        $hoodie = KamsProduct::query()->where('company_id', $company->id)->where('sku', 'KA-HD-02')->first();
        $gomaDepot = $gomaOffice ? $inventory->officeDepot($gomaOffice) : null;
        $kinDepot = $kinOffice ? $inventory->officeDepot($kinOffice) : null;

        if (! KamsStockTransfer::query()->where('company_id', $company->id)->exists() && $product && $gomaDepot && $inventory->quantityAt($northWarehouse, $product) >= 60) {
            $inventory->transfer($northWarehouse, $gomaDepot, [
                ['product_id' => $product->id, 'quantity' => 40],
                ...($hoodie && $inventory->quantityAt($northWarehouse, $hoodie) >= 20 ? [['product_id' => $hoodie->id, 'quantity' => 20]] : []),
            ], $adminId, 'Réapprovisionnement bureau Goma', true);
            if ($kinDepot && $inventory->quantityAt($northWarehouse, $product) >= 20) {
                $inventory->transfer($northWarehouse, $kinDepot, [
                    ['product_id' => $product->id, 'quantity' => 20],
                ], $adminId, 'Réapprovisionnement bureau Kinshasa', true);
            }
        }

        $agent = KamsMember::query()->where('company_id', $company->id)->where('status', 'active')->first();
        $client = KamsClient::query()->where('company_id', $company->id)->first();
        if ($agent && $product && $gomaDepot && $inventory->quantityAt($gomaDepot, $product) >= 10
            && ! KamsStockAssignment::query()->where('company_id', $company->id)->exists()) {
            $inventory->assign($agent, [['product_id' => $product->id, 'quantity' => 10]], $adminId, 'Stock confié — tournée Himbi');
            $sale = $inventory->sell(
                $agent,
                [['product_id' => $product->id, 'quantity' => 4, 'unit_price' => (float) $product->price]],
                $client?->id,
                $client?->name,
                (float) $product->price * 4,
                $adminId,
                'Vente Client X'
            );
            $return = $inventory->createReturn(
                'customer',
                [['product_id' => $product->id, 'quantity' => 1]],
                $agent,
                $sale,
                null,
                null,
                'damaged',
                'Produit endommagé',
                $adminId,
            );
            if ($adminId) {
                $inventory->acceptReturn($return, (int) $adminId);
            }
        }
    }

    private function seedKamsAccounts(): void
    {
        $wallets = app(KamsAccountProvisioner::class);
        KamsMember::query()->each(fn (KamsMember $member) => $wallets->ensureWallet($member));
        User::query()->where('is_active', true)->with('roles')->each(function (User $user) use ($wallets) {
            $wallets->tryForUser($user);
        });
        Company::query()->whereHas('module', fn ($q) => $q->where('slug', 'kams'))->each(function (Company $company) use ($wallets) {
            $wallets->ensureCompanyBooks((int) $company->id);
        });
    }

    private function seedInbox(User $admin, Company $company): void
    {
        if (! AppNotification::query()->where('user_id', $admin->id)->exists()) {
            AppNotification::query()->create([
                'user_id' => $admin->id,
                'type' => 'approval',
                'title' => 'Nouvelle commande KAMS',
                'body' => 'La commande KA-260911-DEMO attend une validation commerciale.',
                'data' => ['module' => 'kams'],
            ]);
            AppNotification::query()->create([
                'user_id' => $admin->id,
                'type' => 'alert',
                'title' => 'Module Heavy Equipment',
                'body' => 'Le module LALI Heavy Equipment est en cours de développement.',
                'data' => ['module' => 'heavy-equipment'],
            ]);
        }

        Task::query()->updateOrCreate([
            'user_id' => $admin->id,
            'title' => 'Valider le rapport consolidé du groupe',
        ], [
            'company_id' => $company->id,
            'status' => 'pending',
            'priority' => 'high',
            'due_date' => now()->addDays(2),
        ]);
    }
}
