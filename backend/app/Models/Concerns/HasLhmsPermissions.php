<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\SystemModule;
use App\Models\UserScope;

trait HasLhmsPermissions
{
    public function roles()
    {
        return $this->belongsToMany(Role::class)->withPivot('company_id');
    }

    public function scopes()
    {
        return $this->hasMany(UserScope::class);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_administrator');
    }

    public function isPartner(): bool
    {
        return $this->hasRole('partner');
    }

    public function isPresident(): bool
    {
        return $this->hasRole('president');
    }

    public function hasFullAccess(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->hasFullAccess());
    }

    public function isGroupAdmin(): bool
    {
        return $this->hasFullAccess();
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->hasFullAccess()) {
            return true;
        }

        return in_array($slug, $this->permissionSlugs(), true);
    }

    public function hasAnyPermission(string ...$slugs): bool
    {
        if ($this->hasFullAccess()) {
            return true;
        }

        $owned = $this->permissionSlugs();

        foreach ($slugs as $slug) {
            if (in_array($slug, $owned, true)) {
                return true;
            }
        }

        return false;
    }

    public function permissionSlugs(): array
    {
        return $this->roles
            ->loadMissing('permissions')
            ->flatMap(fn (Role $role) => $role->permissions->pluck('slug'))
            ->unique()
            ->values()
            ->all();
    }

    public function accessibleCompanyIds(): array
    {
        if ($this->isGroupAdmin()) {
            return Company::query()->pluck('id')->all();
        }

        $ids = collect($this->roles->pluck('pivot.company_id'));

        if ($this->primary_company_id) {
            $ids->push($this->primary_company_id);
        }

        $this->scopes->each(function (UserScope $scope) use ($ids) {
            if ($scope->scope_type === 'company' && $scope->scope_id) {
                $ids->push($scope->scope_id);
            }

            if ($scope->scope_type === 'country' && $scope->scope_id) {
                $ids->push(...Company::query()->where('country_id', $scope->scope_id)->pluck('id'));
            }

            if ($scope->scope_type === 'city' && $scope->scope_id) {
                $ids->push(...Company::query()->where('city_id', $scope->scope_id)->pluck('id'));
            }

            if ($scope->scope_type === 'office' && $scope->scope_id) {
                $companyId = Office::query()->whereKey($scope->scope_id)->value('company_id');
                if ($companyId) {
                    $ids->push($companyId);
                }
            }

            if ($scope->scope_type === 'module' && $scope->scope_id) {
                $companyId = Company::query()->where('system_module_id', $scope->scope_id)->value('id');
                if ($companyId) {
                    $ids->push($companyId);
                }
            }
        });

        return $ids->filter()->unique()->values()->all();
    }

    public function canAccessCompany(?int $companyId): bool
    {
        if ($this->isGroupAdmin()) {
            return true;
        }

        if (! $companyId) {
            return false;
        }

        return in_array($companyId, $this->accessibleCompanyIds(), true);
    }

    public function isOfficeScoped(): bool
    {
        return $this->roles->contains(fn (Role $role) => $role->isOfficeScoped());
    }

    public function accessibleOfficeIds(): array
    {
        if ($this->isGroupAdmin()) {
            return Office::query()->pluck('id')->all();
        }

        $ids = collect();

        if ($this->primary_office_id) {
            $ids->push($this->primary_office_id);
        }

        $this->scopes->each(function (UserScope $scope) use ($ids) {
            if ($scope->scope_type === 'office' && $scope->scope_id) {
                $ids->push($scope->scope_id);
            }
        });

        if ($ids->isNotEmpty()) {
            return $ids->filter()->unique()->values()->all();
        }

        return Office::query()
            ->whereIn('company_id', $this->accessibleCompanyIds() ?: [0])
            ->pluck('id')
            ->all();
    }

    public function canAccessOffice(?int $officeId): bool
    {
        if ($this->isGroupAdmin()) {
            return true;
        }

        if (! $officeId) {
            return false;
        }

        return in_array($officeId, $this->accessibleOfficeIds(), true);
    }

    /**
     * @param  list<array{role_id: int, company_id: int|null}>  $assignments
     */
    public function syncRoleAssignments(array $assignments): void
    {
        $this->roles()->detach();

        foreach ($assignments as $assignment) {
            if (empty($assignment['role_id'])) {
                continue;
            }

            $this->roles()->attach((int) $assignment['role_id'], [
                'company_id' => $assignment['company_id'] ?? null,
            ]);
        }

        $this->unsetRelation('roles');
    }

    public function assignCompanyRole(int $roleId, int $companyId): void
    {
        $this->roles()->newPivotQuery()
            ->where('user_id', $this->id)
            ->where('company_id', $companyId)
            ->delete();

        $this->roles()->attach($roleId, ['company_id' => $companyId]);
        $this->unsetRelation('roles');
    }

    /**
     * @return list<array{company_id: int|null, role: array{id: int, name: string, slug: string, module_slug: ?string}, office_ids: list<int>}>
     */
    public function roleAssignmentPayload(): array
    {
        $this->loadMissing(['roles', 'scopes']);
        $officeIds = $this->scopes->where('scope_type', 'office')->pluck('scope_id')->filter()->all();
        $officesByCompany = Office::query()
            ->whereIn('id', $officeIds ?: [0])
            ->get()
            ->groupBy('company_id');

        return $this->roles->map(function (Role $role) use ($officesByCompany) {
            $companyId = $role->pivot->company_id ? (int) $role->pivot->company_id : null;

            return [
                'company_id' => $companyId,
                'role' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'module_slug' => $role->module_slug,
                ],
                'office_ids' => $companyId
                    ? ($officesByCompany->get($companyId)?->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?? [])
                    : [],
            ];
        })->values()->all();
    }

    /**
     * @return list<string>
     */
    public function accessibleModuleSlugs(): array
    {
        if ($this->isGroupAdmin()) {
            return SystemModule::query()->orderBy('sort_order')->pluck('slug')->all();
        }

        $companyIds = $this->roles->pluck('pivot.company_id')->filter()->unique()->values()->all();

        return Company::query()
            ->with('module')
            ->whereIn('id', $companyIds ?: [0])
            ->get()
            ->map(fn (Company $company) => $company->module?->slug)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
