<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\RoleCatalog;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    use RespondsWithJson;

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions')
            ->orderByDesc('level')
            ->get()
            ->map(fn (Role $role) => $this->present($role));

        return $this->ok($roles);
    }

    public function permissions(Request $request): JsonResponse
    {
        $actionOrder = array_flip(['view', 'create', 'deposit', 'withdraw', 'expense', 'update', 'delete', 'import', 'export', 'print', 'preview', 'activate', 'approve', 'manage', 'group', 'company']);
        $pageOrder = array_flip(['view', 'users', 'settings', 'account', 'company']);
        $user = $request->user();
        $grantableSlugs = $user && ! $user->hasFullAccess()
            ? $user->permissionSlugs()
            : null;

        $groups = Permission::query()
            ->orderBy('group')
            ->orderBy('slug')
            ->get()
            ->when($grantableSlugs !== null, fn ($permissions) => $permissions->whereIn('slug', $grantableSlugs))
            ->groupBy('group')
            ->map(fn ($permissions) => $permissions->sortBy(function (Permission $permission) use ($actionOrder, $pageOrder) {
                $parts = explode('.', $permission->slug);
                $action = last($parts);
                $root = $parts[0] ?? '';
                $page = match ($root) {
                    'administration' => 'view',
                    'users' => 'users',
                    'settings', 'organization', 'companies', 'roles' => 'settings',
                    default => count($parts) >= 4 ? $parts[2] : '',
                };

                return (($pageOrder[$page] ?? 99) * 100) + ($actionOrder[$action] ?? 99);
            })->values())
            ->filter(fn ($permissions) => $permissions->isNotEmpty());

        return $this->ok($groups);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'description' => ['nullable', 'string'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['exists:permissions,id'],
        ]);

        if (array_key_exists('description', $data)) {
            $role->description = $data['description'];
            $role->save();
        }

        if ($role->hasFullAccess()) {
            $role->permissions()->sync(Permission::query()->pluck('id'));
        } elseif ($request->has('permission_ids')) {
            $role->permissions()->sync($this->grantablePermissionIds(
                $request->user(),
                $role,
                $request->input('permission_ids', []),
            ));
        }

        return $this->ok($this->present($role->fresh(['permissions'])), __('Role updated.'));
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function grantablePermissionIds(?User $user, Role $role, array $ids): array
    {
        $selected = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();

        if (! $user || $user->hasFullAccess()) {
            return $this->dependentPermissionIds($selected->all());
        }

        $grantableIds = Permission::query()
            ->whereIn('slug', $user->permissionSlugs() ?: [''])
            ->pluck('id');

        $untouchable = $role->permissions()
            ->whereNotIn('permissions.id', $grantableIds->all() ?: [0])
            ->pluck('permissions.id');

        $selected = $selected
            ->intersect($grantableIds)
            ->merge($untouchable)
            ->unique()
            ->values();

        return $this->dependentPermissionIds($selected->all());
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function dependentPermissionIds(array $ids): array
    {
        $selected = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        $permissions = Permission::query()->whereIn('id', $selected->all() ?: [0])->get();
        $prefixes = $permissions
            ->map(fn (Permission $permission) => $this->permissionPrefix($permission->slug))
            ->unique()
            ->filter()
            ->values();

        if ($prefixes->isEmpty()) {
            return $selected->all();
        }

        $related = Permission::query()
            ->where(function ($query) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $query->orWhere('slug', 'like', $prefix.'.%');
                }
            })
            ->get()
            ->groupBy(fn (Permission $permission) => $this->permissionPrefix($permission->slug));

        foreach ($related as $siblings) {
            $view = $siblings->first(fn (Permission $permission) => str_ends_with($permission->slug, '.view'));
            if ($view && ! $selected->contains($view->id)) {
                $selected = $selected->reject(fn ($id) => $siblings->contains('id', $id))->values();
            }
        }

        $adminView = Permission::query()->where('slug', 'administration.view')->first();
        if ($adminView && ! $selected->contains($adminView->id)) {
            $adminIds = Permission::query()->where('group', 'administration')->pluck('id');
            $selected = $selected->reject(fn ($id) => $adminIds->contains($id))->values();
        }

        $dashboardView = Permission::query()->where('slug', 'kams.dashboard.view')->first();
        if ($dashboardView && ! $selected->contains($dashboardView->id)) {
            $dashboardIds = Permission::query()
                ->where('slug', 'like', 'kams.dashboard.%')
                ->pluck('id');
            $selected = $selected->reject(fn ($id) => $dashboardIds->contains($id))->values();
        }

        return $selected->all();
    }

    private function permissionPrefix(string $slug): string
    {
        $parts = explode('.', $slug);
        array_pop($parts);

        return implode('.', $parts);
    }

    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'module_slug' => $role->module_slug,
            'description' => $role->description,
            'level' => $role->level,
            'is_system' => $role->is_system,
            'full_access' => $role->hasFullAccess() || RoleCatalog::isFullAccess((string) $role->slug),
            'office_scoped' => $role->isOfficeScoped(),
            'permissions' => $role->permissions,
        ];
    }
}
