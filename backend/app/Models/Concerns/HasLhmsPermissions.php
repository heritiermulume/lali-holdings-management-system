<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Models\Role;
use App\Models\UserScope;

trait HasLhmsPermissions
{
    public function roles()
    {
        return $this->belongsToMany(Role::class);
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

    public function isGroupAdmin(): bool
    {
        return $this->hasRole('group_administrator') || $this->isSuperAdmin();
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

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($slug, $this->permissionSlugs(), true);
    }

    public function accessibleCompanyIds(): array
    {
        if ($this->isGroupAdmin()) {
            return Company::query()->pluck('id')->all();
        }

        $ids = collect();

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
                $companyId = \App\Models\Office::query()->whereKey($scope->scope_id)->value('company_id');
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
}
