<?php

namespace Database\Seeders;

use App\Core\Support\PermissionCatalog;
use App\Models\Activity;
use App\Models\AppNotification;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Department;
use App\Models\Office;
use App\Models\Permission;
use App\Models\Province;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SystemModule;
use App\Models\Task;
use App\Models\User;
use App\Modules\Kams\Models\KamsCampaign;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsOrderItem;
use App\Modules\Kams\Models\KamsProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPermissionsAndRoles();
        $geography = $this->seedGeography();
        $companies = $this->seedCompanies($geography);
        $this->seedModules($companies);
        $admin = $this->seedUsers($companies);
        $this->seedSettings();
        $this->seedKams($companies['king']);
        $this->seedInbox($admin, $companies['king']);
    }

    private function seedPermissionsAndRoles(): void
    {
        $permissions = collect(PermissionCatalog::core())
            ->concat(PermissionCatalog::forModule('kams'))
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

        $roles = [
            ['name' => 'Super Administrator', 'slug' => 'super_administrator', 'level' => 100, 'all' => true],
            ['name' => 'Group Administrator', 'slug' => 'group_administrator', 'level' => 90, 'except' => []],
            ['name' => 'Country Manager', 'slug' => 'country_manager', 'level' => 80, 'permissions' => [
                'dashboard.group', 'dashboard.company', 'organization.view', 'companies.view', 'users.view',
                'finance.view', 'reports.view', 'reports.export', 'modules.view', 'notifications.view',
            ]],
            ['name' => 'Company Administrator', 'slug' => 'company_administrator', 'level' => 70, 'permissions' => [
                'dashboard.company', 'organization.view', 'companies.view', 'companies.update', 'users.view',
                'users.create', 'users.update', 'finance.view', 'reports.view', 'modules.view', 'kams.view',
                'kams.create', 'kams.update', 'kams.approve', 'notifications.view',
            ]],
            ['name' => 'City Manager', 'slug' => 'city_manager', 'level' => 60, 'permissions' => [
                'dashboard.company', 'organization.view', 'companies.view', 'users.view', 'reports.view', 'notifications.view',
            ]],
            ['name' => 'Office Manager', 'slug' => 'office_manager', 'level' => 50, 'permissions' => [
                'dashboard.company', 'organization.view', 'users.view', 'kams.view', 'kams.create', 'notifications.view',
            ]],
            ['name' => 'Department Manager', 'slug' => 'department_manager', 'level' => 40, 'permissions' => [
                'dashboard.company', 'users.view', 'kams.view', 'notifications.view',
            ]],
            ['name' => 'Employee / Staff', 'slug' => 'employee', 'level' => 20, 'permissions' => [
                'dashboard.company', 'kams.view', 'notifications.view',
            ]],
            ['name' => 'Viewer', 'slug' => 'viewer', 'level' => 10, 'permissions' => [
                'dashboard.company', 'organization.view', 'reports.view', 'notifications.view',
            ]],
        ];

        foreach ($roles as $roleData) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['name'],
                    'level' => $roleData['level'],
                    'is_system' => true,
                ]
            );

            if (! empty($roleData['all'])) {
                $role->permissions()->sync($permissionIds->values());
                continue;
            }

            if (array_key_exists('except', $roleData)) {
                $role->permissions()->sync($permissionIds->values());
                continue;
            }

            $role->permissions()->sync(
                collect($roleData['permissions'])->map(fn ($slug) => $permissionIds[$slug] ?? null)->filter()
            );
        }
    }

    private function seedGeography(): array
    {
        $drc = Country::query()->updateOrCreate(['iso_code' => 'CD'], [
            'name' => 'Democratic Republic of the Congo',
            'name_fr' => 'République démocratique du Congo',
            'phone_code' => '+243',
            'currency_code' => 'USD',
            'currency_symbol' => '$',
        ]);

        $tz = Country::query()->updateOrCreate(['iso_code' => 'TZ'], [
            'name' => 'Tanzania',
            'name_fr' => 'Tanzanie',
            'phone_code' => '+255',
            'currency_code' => 'TZS',
            'currency_symbol' => 'TSh',
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

        return compact('drc', 'tz', 'kinshasa', 'goma', 'zanzibarCity');
    }

    private function seedCompanies(array $geo): array
    {
        $holdings = Company::query()->updateOrCreate(['code' => 'LALI'], [
            'country_id' => $geo['drc']->id,
            'city_id' => $geo['kinshasa']->id,
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
            'name' => 'King Attitude',
            'legal_name' => 'King Attitude SARL',
            'email' => 'hello@kingattitude.com',
            'phone' => '+243 900 000 001',
            'address' => 'Goma, Nord-Kivu',
            'currency_code' => 'USD',
            'description' => 'Fashion and lifestyle brand of LALI Holdings',
        ]);

        $rice = Company::query()->updateOrCreate(['code' => 'LMRM'], [
            'country_id' => $geo['tz']->id,
            'city_id' => $geo['zanzibarCity']->id,
            'name' => 'LALI Miembeni Rice Mills',
            'legal_name' => 'LALI Miembeni Rice Mills Ltd',
            'email' => 'rice@laliholdings.com',
            'phone' => '+255 700 000 002',
            'address' => 'Miembeni, Zanzibar',
            'currency_code' => 'TZS',
            'description' => 'Rice milling and distribution',
        ]);

        $hq = Office::query()->updateOrCreate(['company_id' => $holdings->id, 'code' => 'HQ'], [
            'city_id' => $geo['kinshasa']->id,
            'name' => 'Group Headquarters',
            'type' => 'headquarters',
            'address' => 'Kinshasa',
        ]);

        $gomaOffice = Office::query()->updateOrCreate(['company_id' => $king->id, 'code' => 'GMA'], [
            'city_id' => $geo['goma']->id,
            'name' => 'Goma Flagship',
            'type' => 'headquarters',
            'address' => 'Goma',
        ]);

        Office::query()->updateOrCreate(['company_id' => $rice->id, 'code' => 'MMB'], [
            'city_id' => $geo['zanzibarCity']->id,
            'name' => 'Miembeni Mill',
            'type' => 'headquarters',
            'address' => 'Miembeni',
        ]);

        Department::query()->updateOrCreate(['company_id' => $holdings->id, 'code' => 'FIN'], [
            'office_id' => $hq->id,
            'name' => 'Finance',
        ]);
        Department::query()->updateOrCreate(['company_id' => $king->id, 'code' => 'COM'], [
            'office_id' => $gomaOffice->id,
            'name' => 'Commercial',
        ]);

        Activity::query()->updateOrCreate(['company_id' => $king->id, 'name' => 'Retail fashion']);
        Activity::query()->updateOrCreate(['company_id' => $rice->id, 'name' => 'Rice milling']);

        return compact('holdings', 'king', 'rice', 'hq', 'gomaOffice');
    }

    private function seedModules(array $companies): void
    {
        $modules = [
            ['slug' => 'kams', 'name' => 'King Attitude Management System', 'name_fr' => 'King Attitude Management System', 'status' => 'active', 'sort_order' => 1, 'icon' => 'crown'],
            ['slug' => 'heavy-equipment', 'name' => 'LALI Heavy Equipment', 'name_fr' => 'LALI Heavy Equipment', 'status' => 'in_development', 'sort_order' => 2, 'icon' => 'truck'],
            ['slug' => 'real-estate', 'name' => 'LALI Apartments / Real Estate', 'name_fr' => 'LALI Apartments / Immobilier', 'status' => 'coming_soon', 'sort_order' => 3, 'icon' => 'building'],
            ['slug' => 'rice-mills', 'name' => 'LALI Miembeni Rice Mills', 'name_fr' => 'LALI Miembeni Rice Mills', 'status' => 'coming_soon', 'sort_order' => 4, 'icon' => 'wheat'],
            ['slug' => 'lali-tv', 'name' => 'LALI TV', 'name_fr' => 'LALI TV', 'status' => 'inactive', 'sort_order' => 5, 'icon' => 'tv'],
        ];

        foreach ($modules as $module) {
            $record = SystemModule::query()->updateOrCreate(['slug' => $module['slug']], $module + [
                'description' => $module['name'],
                'description_fr' => $module['name_fr'],
                'version' => $module['slug'] === 'kams' ? '1.0.0' : '0.1.0',
            ]);

            $companies['king']->modules()->syncWithoutDetaching([
                $record->id => ['is_enabled' => $module['slug'] === 'kams'],
            ]);
            $companies['holdings']->modules()->syncWithoutDetaching([
                $record->id => ['is_enabled' => true],
            ]);
        }
    }

    private function seedUsers(array $companies): User
    {
        $admin = User::query()->updateOrCreate(['email' => 'samuel.w@example.com'], [
            'first_name' => 'Héritier',
            'last_name' => 'Mulume',
            'phone' => '+243 800 111 222',
            'locale' => 'fr',
            'timezone' => 'Africa/Kinshasa',
            'is_active' => true,
            'primary_company_id' => $companies['holdings']->id,
            'primary_office_id' => $companies['hq']->id,
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
        ]);
        $admin->roles()->sync([Role::query()->where('slug', 'super_administrator')->value('id')]);
        $admin->scopes()->updateOrCreate(['scope_type' => 'group'], ['scope_id' => null]);

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
        $companyAdmin->roles()->sync([Role::query()->where('slug', 'company_administrator')->value('id')]);
        $companyAdmin->scopes()->updateOrCreate(
            ['scope_type' => 'company', 'scope_id' => $companies['king']->id],
            []
        );

        return $admin;
    }

    private function seedSettings(): void
    {
        $settings = [
            ['group' => 'general', 'key' => 'group_name', 'value' => 'LALI Holdings'],
            ['group' => 'general', 'key' => 'default_locale', 'value' => 'fr'],
            ['group' => 'general', 'key' => 'default_timezone', 'value' => 'Africa/Kinshasa'],
            ['group' => 'finance', 'key' => 'reporting_currency', 'value' => 'USD'],
            ['group' => 'notifications', 'key' => 'email_enabled', 'value' => '0', 'type' => 'boolean'],
            ['group' => 'notifications', 'key' => 'sms_enabled', 'value' => '0', 'type' => 'boolean'],
        ];

        foreach ($settings as $setting) {
            Setting::query()->updateOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type'] ?? 'string']
            );
        }
    }

    private function seedKams(Company $company): void
    {
        $clients = collect([
            ['name' => 'Boutique Horizon', 'email' => 'horizon@client.cd', 'city' => 'Goma', 'segment' => 'wholesale'],
            ['name' => 'Maison Kivu', 'email' => 'kivu@client.cd', 'city' => 'Goma', 'segment' => 'retail'],
            ['name' => 'Urban Attitude', 'email' => 'urban@client.cd', 'city' => 'Kinshasa', 'segment' => 'retail'],
        ])->map(fn ($client) => KamsClient::query()->updateOrCreate(
            ['company_id' => $company->id, 'email' => $client['email']],
            $client + ['status' => 'active']
        ));

        $products = collect([
            ['sku' => 'KA-TEE-01', 'name' => 'King Attitude Tee', 'category' => 'Apparel', 'collection' => 'Core', 'price' => 35, 'stock' => 180],
            ['sku' => 'KA-HD-02', 'name' => 'Crown Hoodie', 'category' => 'Apparel', 'collection' => 'Essentials', 'price' => 72, 'stock' => 90],
            ['sku' => 'KA-CAP-03', 'name' => 'Signature Cap', 'category' => 'Accessories', 'collection' => 'Core', 'price' => 28, 'stock' => 140],
            ['sku' => 'KA-BAG-04', 'name' => 'Attitude Tote', 'category' => 'Accessories', 'collection' => 'Limited', 'price' => 46, 'stock' => 60],
        ])->map(fn ($product) => KamsProduct::query()->updateOrCreate(
            ['company_id' => $company->id, 'sku' => $product['sku']],
            $product + ['status' => 'active']
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

        KamsCampaign::query()->updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Autumn Attitude'],
            [
                'channel' => 'social',
                'status' => 'active',
                'budget' => 1800,
                'starts_on' => now()->subDays(10),
                'ends_on' => now()->addDays(20),
                'description' => 'Launch campaign for the new collection.',
            ]
        );
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
