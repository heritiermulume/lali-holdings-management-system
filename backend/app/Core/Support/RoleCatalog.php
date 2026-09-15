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
            ['name' => 'Partner', 'slug' => 'partner', 'level' => 80, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => false],
            ['name' => 'Divisionnaire', 'slug' => 'divisionnaire', 'level' => 70, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => false],
            ['name' => 'Manager', 'slug' => 'manager', 'level' => 60, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
            ['name' => 'Secretary', 'slug' => 'secretary', 'level' => 50, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
            ['name' => 'Assistant Manager', 'slug' => 'assistant_manager', 'level' => 40, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
            ['name' => 'Leader', 'slug' => 'leader', 'level' => 30, 'full_access' => false, 'module_slug' => 'kams', 'office_scoped' => true],
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
            'viewer' => 'leader',
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
            'président' => 'president',
            'presidente' => 'president',
            'présidente' => 'president',
            'président(e)' => 'president',
            'president' => 'president',
            'partenaire' => 'partner',
            'partner' => 'partner',
            'divisionnaire' => 'divisionnaire',
            'manager' => 'manager',
            'secrétaire' => 'secretary',
            'secretaire' => 'secretary',
            'secretary' => 'secretary',
            'assistant manager' => 'assistant_manager',
            'assistante manager' => 'assistant_manager',
            'assistant(e) manager' => 'assistant_manager',
            'leader' => 'leader',
            'distributeur' => 'distributor',
            'distributrice' => 'distributor',
            'distributeur/distributrice' => 'distributor',
            'distributeur/discributrice' => 'distributor',
            'distributor' => 'distributor',
        ];

        return $aliases[$normalized] ?? null;
    }
}
