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
        'print',
        'manage',
    ];

    public static function core(): array
    {
        return [
            ['slug' => 'dashboard.group', 'group' => 'dashboard', 'name' => 'View group dashboard'],
            ['slug' => 'dashboard.company', 'group' => 'dashboard', 'name' => 'View company dashboard'],

            ['slug' => 'users.view', 'group' => 'users', 'name' => 'View users'],
            ['slug' => 'users.create', 'group' => 'users', 'name' => 'Create users'],
            ['slug' => 'users.update', 'group' => 'users', 'name' => 'Update users'],
            ['slug' => 'users.delete', 'group' => 'users', 'name' => 'Delete users'],
            ['slug' => 'users.manage', 'group' => 'users', 'name' => 'Manage users and assignments'],

            ['slug' => 'roles.view', 'group' => 'roles', 'name' => 'View roles'],
            ['slug' => 'roles.manage', 'group' => 'roles', 'name' => 'Manage roles and permissions'],

            ['slug' => 'organization.view', 'group' => 'organization', 'name' => 'View organization'],
            ['slug' => 'organization.create', 'group' => 'organization', 'name' => 'Create organization units'],
            ['slug' => 'organization.update', 'group' => 'organization', 'name' => 'Update organization units'],
            ['slug' => 'organization.delete', 'group' => 'organization', 'name' => 'Delete organization units'],

            ['slug' => 'companies.view', 'group' => 'companies', 'name' => 'View companies'],
            ['slug' => 'companies.create', 'group' => 'companies', 'name' => 'Create companies'],
            ['slug' => 'companies.update', 'group' => 'companies', 'name' => 'Update companies'],
            ['slug' => 'companies.delete', 'group' => 'companies', 'name' => 'Delete companies'],

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
            ['slug' => 'audit.view', 'group' => 'audit', 'name' => 'View audit logs'],
            ['slug' => 'settings.manage', 'group' => 'settings', 'name' => 'Manage settings'],
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
}
