<?php

namespace App\Core\Support;

class PermissionCatalog
{
    public const ACTIONS = [
        'view',
        'create',
        'update',
        'delete',
        'approve',
        'export',
        'import',
        'print',
        'preview',
        'activate',
        'manage',
        'deposit',
        'withdraw',
        'expense',
    ];

    public static function core(): array
    {
        return [
            ['slug' => 'administration.view', 'group' => 'administration', 'name' => 'View administration'],

            ['slug' => 'users.view', 'group' => 'administration', 'name' => 'View users'],
            ['slug' => 'users.create', 'group' => 'administration', 'name' => 'Create users'],
            ['slug' => 'users.update', 'group' => 'administration', 'name' => 'Update users'],
            ['slug' => 'users.delete', 'group' => 'administration', 'name' => 'Delete users'],
            ['slug' => 'users.manage', 'group' => 'administration', 'name' => 'Manage users and assignments'],
            ['slug' => 'users.import', 'group' => 'administration', 'name' => 'Import users'],
            ['slug' => 'users.export', 'group' => 'administration', 'name' => 'Export users'],
            ['slug' => 'users.print', 'group' => 'administration', 'name' => 'Print users'],
            ['slug' => 'users.preview', 'group' => 'administration', 'name' => 'Preview users'],

            ['slug' => 'roles.view', 'group' => 'administration', 'name' => 'View roles'],
            ['slug' => 'roles.manage', 'group' => 'administration', 'name' => 'Manage roles and permissions'],

            ['slug' => 'organization.view', 'group' => 'administration', 'name' => 'View organization'],
            ['slug' => 'organization.create', 'group' => 'administration', 'name' => 'Create organization units'],
            ['slug' => 'organization.update', 'group' => 'administration', 'name' => 'Update organization units'],
            ['slug' => 'organization.delete', 'group' => 'administration', 'name' => 'Delete organization units'],

            ['slug' => 'companies.view', 'group' => 'administration', 'name' => 'View companies'],
            ['slug' => 'companies.create', 'group' => 'administration', 'name' => 'Create companies'],
            ['slug' => 'companies.update', 'group' => 'administration', 'name' => 'Update companies'],
            ['slug' => 'companies.delete', 'group' => 'administration', 'name' => 'Delete companies'],

            ['slug' => 'notifications.view', 'group' => 'notifications', 'name' => 'View notifications'],
            ['slug' => 'settings.manage', 'group' => 'administration', 'name' => 'Manage settings'],
        ];
    }

    public static function forModule(string $module, array $extra = []): array
    {
        $base = [];

        foreach (['view', 'create', 'update', 'delete', 'approve', 'export'] as $action) {
            $base[] = [
                'slug' => "{$module}.{$action}",
                'group' => $module,
                'name' => ucfirst($action).' '.$module,
            ];
        }

        return array_merge($base, $extra);
    }

    /**
     * @return list<array{slug: string, group: string, name: string}>
     */
    public static function forKams(): array
    {
        $areas = [
            'dashboard' => ['view'],
            'dashboard.products' => ['view'],
            'dashboard.orders' => ['view'],
            'dashboard.finance' => ['view'],
            'dashboard.recruitment' => ['view'],
            'members.statistics' => ['view'],
            'members.agents' => ['view'],
            'members' => ['create', 'update', 'delete', 'import', 'export', 'print', 'preview', 'activate'],
            'recruitment.applications' => ['view'],
            'recruitment' => ['create', 'update', 'delete'],
            'recruitment.reports' => ['view', 'print', 'preview'],
            'recruitment.interview' => ['update'],
            'recruitment.observation' => ['update'],
            'clients' => ['view', 'create', 'update', 'delete'],
            'suppliers' => ['view'],
            'patrimoine' => ['view', 'create', 'update', 'delete'],
            'products' => ['view', 'create', 'update', 'delete'],
            'stock' => ['update'],
            'stock.statistics' => ['view'],
            'stock.warehouses' => ['view', 'update', 'delete'],
            'stock.depots' => ['view', 'delete'],
            'stock.assignments' => ['view', 'delete'],
            'stock.reports' => ['view', 'print', 'preview', 'delete'],
            'stock.journal' => ['view', 'delete'],
            'orders' => ['view'],
            'finance.dashboard' => ['view', 'update'],
            'finance.accounts' => ['view'],
            'finance.account' => ['view', 'deposit', 'withdraw', 'update', 'print', 'preview'],
            'finance.company' => ['view', 'deposit', 'withdraw', 'expense', 'approve', 'print', 'preview'],
        ];

        $permissions = [];

        foreach ($areas as $area => $actions) {
            $group = match (true) {
                str_starts_with($area, 'finance.') => 'kams.finance',
                str_starts_with($area, 'dashboard.') => 'kams.dashboard',
                str_starts_with($area, 'members') => 'kams.members',
                str_starts_with($area, 'recruitment') => 'kams.recruitment',
                $area === 'clients' || $area === 'suppliers' => 'kams.clients',
                $area === 'patrimoine' => 'kams.patrimoine',
                $area === 'products' || str_starts_with($area, 'stock') || $area === 'orders' => 'kams.stock',
                default => "kams.{$area}",
            };
            foreach ($actions as $action) {
                $permissions[] = [
                    'slug' => "kams.{$area}.{$action}",
                    'group' => $group,
                    'name' => ucfirst($action).' kams '.$area,
                ];
            }
        }

        return $permissions;
    }
}
