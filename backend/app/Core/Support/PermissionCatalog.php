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

            ['slug' => 'finance.view', 'group' => 'finance', 'name' => 'View finances'],
            ['slug' => 'finance.create', 'group' => 'finance', 'name' => 'Create financial records'],
            ['slug' => 'finance.update', 'group' => 'finance', 'name' => 'Update financial records'],
            ['slug' => 'finance.approve', 'group' => 'finance', 'name' => 'Approve financial records'],
            ['slug' => 'finance.export', 'group' => 'finance', 'name' => 'Export financial data'],

            ['slug' => 'reports.view', 'group' => 'reports', 'name' => 'View reports'],
            ['slug' => 'reports.export', 'group' => 'reports', 'name' => 'Export reports'],
            ['slug' => 'reports.print', 'group' => 'reports', 'name' => 'Print reports'],

            ['slug' => 'modules.view', 'group' => 'modules', 'name' => 'View modules'],
            ['slug' => 'modules.manage', 'group' => 'modules', 'name' => 'Activate and configure modules'],

            ['slug' => 'notifications.view', 'group' => 'notifications', 'name' => 'View notifications'],
            ['slug' => 'audit.view', 'group' => 'administration', 'name' => 'View audit logs'],
            ['slug' => 'settings.manage', 'group' => 'administration', 'name' => 'Manage settings'],
            ['slug' => 'files.manage', 'group' => 'files', 'name' => 'Manage files'],
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
            'members' => ['view', 'create', 'update', 'delete', 'import', 'export', 'print', 'preview', 'activate'],
            'recruitment' => ['view', 'create', 'update', 'delete', 'approve'],
            'clients' => ['view', 'create', 'update', 'delete'],
            'products' => ['view', 'create', 'update', 'delete'],
            'orders' => ['view', 'create', 'update', 'export', 'print'],
            'finance.dashboard' => ['view', 'update'],
            'finance.account' => ['view', 'deposit', 'withdraw', 'update', 'print', 'preview'],
            'finance.company' => ['view', 'deposit', 'withdraw', 'expense', 'approve', 'print', 'preview'],
            'campaigns' => ['view', 'create', 'update', 'delete'],
        ];

        $permissions = [];

        foreach ($areas as $area => $actions) {
            $group = match (true) {
                str_starts_with($area, 'finance.') => 'kams.finance',
                str_starts_with($area, 'dashboard.') => 'kams.dashboard',
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
