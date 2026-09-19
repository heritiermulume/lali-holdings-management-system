<?php

namespace App\Core\Support;

class RoleCatalog
{
    /**
     * @return list<array{name: string, slug: string, level: int, full_access: bool, module_slug: ?string, office_scoped: bool}>
     */
    public static function all(): array
    {
        return [
            ['name' => 'Super Administrator', 'slug' => 'super_administrator', 'level' => 100, 'full_access' => true, 'module_slug' => null, 'office_scoped' => false],
            ['name' => 'President', 'slug' => 'president', 'level' => 90, 'full_access' => true, 'module_slug' => null, 'office_scoped' => false],
            ['name' => 'Partner', 'slug' => 'partner', 'level' => 85, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => false],
            ['name' => 'Vice President', 'slug' => 'vice_president', 'level' => 80, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => false],
            ['name' => 'Divisionnaire', 'slug' => 'divisionnaire', 'level' => 70, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => false],
            ['name' => 'Manager', 'slug' => 'manager', 'level' => 60, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
            ['name' => 'Secretary', 'slug' => 'secretary', 'level' => 50, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
            ['name' => 'Assistant Manager', 'slug' => 'assistant_manager', 'level' => 40, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
            ['name' => 'Chef', 'slug' => 'chef', 'level' => 30, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
            ['name' => 'Distributor', 'slug' => 'distributor', 'level' => 20, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
        ];
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    /**
     * @return list<string>
     */
    public static function fullAccessSlugs(): array
    {
        return collect(self::all())->where('full_access', true)->pluck('slug')->all();
    }

    public static function isFullAccess(string $slug): bool
    {
        return in_array($slug, self::fullAccessSlugs(), true);
    }

    /**
     * @return list<string>
     */
    public static function kamsCentralSlugs(): array
    {
        return ['partner', 'president', 'super_administrator'];
    }

    public static function isOfficeScoped(string $slug): bool
    {
        $role = collect(self::all())->firstWhere('slug', $slug);

        return (bool) ($role['office_scoped'] ?? false);
    }

    public static function moduleSlug(string $slug): ?string
    {
        return collect(self::all())->firstWhere('slug', $slug)['module_slug'] ?? null;
    }

    /**
     * @return list<array{name: string, slug: string, level: int, full_access: bool, module_slug: ?string, office_scoped: bool}>
     */
    public static function forModule(string $moduleSlug): array
    {
        return collect(self::all())->where('module_slug', $moduleSlug)->values()->all();
    }

    /**
     * Permission slugs for a role. Null means every permission (full access).
     *
     * @return list<string>|null
     */
    public static function permissionSlugsFor(string $slug): ?array
    {
        if (self::isFullAccess($slug)) {
            return null;
        }

        $patterns = match ($slug) {
            'partner', 'vice_president' => [
                'kams.*',
                'administration.view',
                'users.view',
                'users.create',
                'users.update',
                'users.import',
                'users.export',
                'users.print',
                'users.preview',
                'roles.view',
                'organization.view',
                'organization.create',
                'organization.update',
                'companies.view',
                'finance.view',
                'reports.view',
                'reports.export',
                'notifications.view',
                'audit.view',
            ],
            'divisionnaire' => [
                'kams.*',
                'administration.view',
                'users.view',
                'organization.view',
                'organization.create',
                'organization.update',
                'companies.view',
                'reports.view',
                'notifications.view',
            ],
            'manager' => [
                'kams.dashboard.view',
                'companies.view',
                'organization.view',
                'organization.create',
                'organization.update',
                'kams.members.view',
                'kams.members.create',
                'kams.members.update',
                'kams.members.import',
                'kams.members.export',
                'kams.members.print',
                'kams.members.preview',
                'kams.members.activate',
                'kams.recruitment.view',
                'kams.recruitment.create',
                'kams.recruitment.update',
                'kams.recruitment.approve',
                'kams.clients.view',
                'kams.clients.create',
                'kams.clients.update',
                'kams.products.view',
                'kams.products.create',
                'kams.products.update',
                'kams.orders.view',
                'kams.orders.create',
                'kams.orders.update',
                'kams.orders.export',
                'kams.orders.print',
                'kams.campaigns.view',
                'kams.campaigns.create',
                'kams.campaigns.update',
                'kams.finance.dashboard.view',
                'kams.finance.dashboard.update',
                'kams.finance.account.view',
                'kams.finance.account.deposit',
                'kams.finance.account.withdraw',
                'kams.finance.account.update',
                'kams.finance.account.print',
                'kams.finance.account.preview',
                'kams.finance.company.view',
                'kams.finance.company.deposit',
                'kams.finance.company.withdraw',
                'kams.finance.company.expense',
                'kams.finance.company.approve',
                'kams.finance.company.print',
                'kams.finance.company.preview',
                'administration.view',
                'notifications.view',
            ],
            'secretary' => [
                'kams.dashboard.view',
                'kams.members.view',
                'kams.members.create',
                'kams.members.update',
                'kams.members.export',
                'kams.members.print',
                'kams.members.preview',
                'kams.recruitment.view',
                'kams.recruitment.create',
                'kams.recruitment.update',
                'kams.clients.view',
                'kams.clients.create',
                'kams.clients.update',
                'kams.products.view',
                'kams.orders.view',
                'kams.orders.create',
                'kams.orders.update',
                'kams.orders.export',
                'kams.orders.print',
                'kams.campaigns.view',
                'kams.finance.dashboard.view',
                'kams.finance.dashboard.update',
                'kams.finance.account.view',
                'kams.finance.account.deposit',
                'kams.finance.account.withdraw',
                'kams.finance.account.update',
                'kams.finance.account.print',
                'kams.finance.account.preview',
                'kams.finance.company.view',
                'kams.finance.company.deposit',
                'kams.finance.company.withdraw',
                'kams.finance.company.expense',
                'kams.finance.company.approve',
                'kams.finance.company.print',
                'kams.finance.company.preview',
                'administration.view',
                'notifications.view',
            ],
            'assistant_manager' => [
                'kams.dashboard.view',
                'kams.members.view',
                'kams.members.update',
                'kams.members.print',
                'kams.members.preview',
                'kams.recruitment.view',
                'kams.recruitment.create',
                'kams.recruitment.update',
                'kams.clients.view',
                'kams.clients.create',
                'kams.clients.update',
                'kams.products.view',
                'kams.orders.view',
                'kams.orders.create',
                'kams.orders.update',
                'kams.campaigns.view',
                'kams.finance.dashboard.view',
                'kams.finance.account.view',
                'kams.finance.account.deposit',
                'kams.finance.account.withdraw',
                'kams.finance.account.print',
                'kams.finance.account.preview',
                'administration.view',
                'notifications.view',
            ],
            'chef' => [
                'kams.dashboard.view',
                'kams.members.view',
                'kams.clients.view',
                'kams.clients.create',
                'kams.products.view',
                'kams.orders.view',
                'kams.orders.create',
                'kams.finance.dashboard.view',
                'kams.finance.account.view',
                'kams.finance.account.deposit',
                'kams.finance.account.withdraw',
                'kams.finance.account.print',
                'kams.finance.account.preview',
                'notifications.view',
            ],
            'distributor' => [
                'kams.dashboard.view',
                'kams.clients.view',
                'kams.clients.create',
                'kams.products.view',
                'kams.orders.view',
                'kams.orders.create',
                'kams.finance.dashboard.view',
                'kams.finance.account.view',
                'kams.finance.account.deposit',
                'kams.finance.account.withdraw',
                'kams.finance.account.print',
                'kams.finance.account.preview',
                'notifications.view',
            ],
            default => [],
        };

        return self::expandPermissionPatterns($patterns);
    }

    /**
     * @param  list<string>  $patterns
     * @return list<string>
     */
    private static function expandPermissionPatterns(array $patterns): array
    {
        $all = collect(PermissionCatalog::core())
            ->concat(PermissionCatalog::forKams())
            ->concat(PermissionCatalog::forModule('kams'))
            ->pluck('slug');

        $slugs = [];
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '.*')) {
                $prefix = substr($pattern, 0, -1);
                $slugs = array_merge($slugs, $all->filter(fn (string $slug) => str_starts_with($slug, $prefix))->all());
            } else {
                $slugs[] = $pattern;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return array<string, string>
     */
    public static function legacySlugMap(): array
    {
        return [
            'group_administrator' => 'president',
            'country_manager' => 'divisionnaire',
            'company_administrator' => 'manager',
            'city_manager' => 'manager',
            'office_manager' => 'manager',
            'department_manager' => 'assistant_manager',
            'employee' => 'distributor',
            'viewer' => 'chef',
            'leader' => 'chef',
        ];
    }

    public static function resolve(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $slug = strtolower(str_replace(['-', ' '], '_', $raw));
        if (in_array($slug, self::slugs(), true)) {
            return $slug;
        }
        if (isset(self::legacySlugMap()[$slug])) {
            return self::legacySlugMap()[$slug];
        }

        $normalized = mb_strtolower($raw);
        $aliases = [
            'super administrateur' => 'super_administrator',
            'super administrator' => 'super_administrator',
            'super admin' => 'super_administrator',
            'président' => 'president',
            'presidente' => 'president',
            'présidente' => 'president',
            'président(e)' => 'president',
            'president' => 'president',
            'partenaire' => 'partner',
            'partner' => 'partner',
            'vice président' => 'vice_president',
            'vice-président' => 'vice_president',
            'vice presidente' => 'vice_president',
            'vice-presidente' => 'vice_president',
            'vice présidente' => 'vice_president',
            'vice-présidente' => 'vice_president',
            'vice president' => 'vice_president',
            'vice-president' => 'vice_president',
            'divisionnaire' => 'divisionnaire',
            'manager' => 'manager',
            'secrétaire' => 'secretary',
            'secretaire' => 'secretary',
            'secretary' => 'secretary',
            'assistant manager' => 'assistant_manager',
            'assistante manager' => 'assistant_manager',
            'assistant(e) manager' => 'assistant_manager',
            'leader' => 'chef',
            'chef' => 'chef',
            'cheffe' => 'chef',
            'distributeur' => 'distributor',
            'distributrice' => 'distributor',
            'distributeur/distributrice' => 'distributor',
            'distributeur/discributrice' => 'distributor',
            'distributor' => 'distributor',
        ];

        return $aliases[$normalized] ?? null;
    }
}
