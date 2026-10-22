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
        return $this->belongsToMany(Role::class)->withPivot('company_id', 'office_id');
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

    /**
     * Reload roles/permissions from the database so revoked grants apply on the next check.
     */
    public function refreshAccessRelations(): static
    {
        $this->unsetRelation('roles');
        $this->load(['roles.permissions']);

        return $this;
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
        return $this->roles->contains(fn (Role $role) => $role->pivot->office_id || $role->isOfficeScoped());
    }

    /**
     * Manager and higher grades filter the offices they created or lead, and the offices under them.
     */
    public function limitsDataToOwnedOffices(): bool
    {
        if ($this->isGroupAdmin()) {
            return false;
        }

        return $this->roles->contains(fn (Role $role) => in_array($role->slug, [
            'manager',
            'divisionnaire',
            'vice_president',
            'partner',
        ], true));
    }

    /**
     * Offices this user created or leads, plus every descendant (children and grandchildren).
     *
     * @return list<int>
     */
    public function ownedOfficeIds(): array
    {
        $roots = Office::query()
            ->where(function ($query) {
                $query->where('creator_user_id', $this->id)
                    ->orWhere('manager_user_id', $this->id);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($roots === []) {
            return [];
        }

        return array_values(array_unique(array_merge($roots, Office::descendantIdsFor($roots))));
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

        foreach ($this->roles as $role) {
            if ($role->pivot->office_id) {
                $ids->push((int) $role->pivot->office_id);
            }
        }

        $this->scopes->each(function (UserScope $scope) use ($ids) {
            if ($scope->scope_type === 'office' && $scope->scope_id) {
                $ids->push($scope->scope_id);
            }
        });

        if ($ids->isNotEmpty()) {
            $assigned = $ids->filter()->unique()->values()->all();
            if ($this->limitsDataToOwnedOffices()) {
                return $this->ownedOfficeIds();
            }
            if ($this->isOfficeScoped()) {
                $assigned = array_values(array_unique(array_merge($assigned, Office::descendantIdsFor($assigned))));
            }

            return $assigned;
        }

        if ($this->limitsDataToOwnedOffices()) {
            $owned = $this->ownedOfficeIds();
            if ($owned !== []) {
                return $owned;
            }
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
     * @param  list<array{role_id: int, company_id: int|null, office_id?: int|null}>  $assignments
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
                'office_id' => $assignment['office_id'] ?? null,
            ]);
        }

        $this->unsetRelation('roles');
    }

    public function assignCompanyRole(int $roleId, int $companyId, ?int $officeId = null): void
    {
        $this->roles()->newPivotQuery()
            ->where('user_id', $this->id)
            ->where('company_id', $companyId)
            ->delete();

        $this->roles()->attach($roleId, [
            'company_id' => $companyId,
            'office_id' => $officeId,
        ]);
        $this->unsetRelation('roles');
    }

    /**
     * @return list<array{
     *   company_id: int|null,
     *   role: array{id: int, name: string, slug: string, module_slug: ?string}|null,
     *   office_ids: list<int>,
     *   offices: list<array{office_id: int, role: array{id: int, name: string, slug: string, module_slug: ?string}}>
     * }>
     */
    public function roleAssignmentPayload(): array
    {
        $this->loadMissing(['roles', 'scopes']);
        $scopedOfficeIds = $this->scopes->where('scope_type', 'office')->pluck('scope_id')->filter()->map(fn ($id) => (int) $id)->all();
        $officesByCompany = Office::query()
            ->whereIn('id', $scopedOfficeIds ?: [0])
            ->get()
            ->groupBy('company_id');

        $payload = [];

        foreach ($this->roles as $role) {
            $companyId = $role->pivot->company_id ? (int) $role->pivot->company_id : null;
            $officeId = $role->pivot->office_id ? (int) $role->pivot->office_id : null;
            $rolePayload = [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'module_slug' => $role->module_slug,
                'office_scoped' => $role->isOfficeScoped(),
            ];

            if (! $companyId) {
                $payload[] = [
                    'company_id' => null,
                    'role' => $rolePayload,
                    'office_ids' => [],
                    'offices' => [],
                ];

                continue;
            }

            $key = 'c'.$companyId;
            if (! isset($payload[$key])) {
                $payload[$key] = [
                    'company_id' => $companyId,
                    'role' => null,
                    'office_ids' => [],
                    'offices' => [],
                ];
            }

            if ($officeId) {
                $payload[$key]['offices'][] = [
                    'office_id' => $officeId,
                    'role' => $rolePayload,
                ];
                $payload[$key]['office_ids'][] = $officeId;
            } else {
                $payload[$key]['role'] = $rolePayload;
            }
        }

        foreach ($payload as $key => $item) {
            if (! is_array($item) || ($item['company_id'] ?? null) === null) {
                continue;
            }

            if ($item['offices'] !== []) {
                $payload[$key]['office_ids'] = array_values(array_unique($item['office_ids']));

                continue;
            }

            $payload[$key]['office_ids'] = $officesByCompany
                ->get($item['company_id'])
                ?->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all() ?? [];
        }

        return array_values($payload);
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
