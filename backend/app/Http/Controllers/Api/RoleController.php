<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Core\Support\RoleCatalog;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Models\Permission;
use App\Models\Role;
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

    public function permissions(): JsonResponse
    {
        $actionOrder = array_flip(['view', 'create', 'update', 'delete', 'import', 'export', 'print', 'preview', 'activate', 'approve', 'manage']);

        $groups = Permission::query()
            ->orderBy('group')
            ->orderBy('slug')
            ->get()
            ->groupBy('group')
            ->map(fn ($permissions) => $permissions->sortBy(function (Permission $permission) use ($actionOrder) {
                $action = last(explode('.', $permission->slug));

                return $actionOrder[$action] ?? 99;
            })->values());

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
            $role->permissions()->sync($this->dependentPermissionIds($request->input('permission_ids', [])));
        }

        AuditLogger::record('update', $role, [], $data);

        return $this->ok($this->present($role->fresh(['permissions'])), __('Role updated.'));
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function dependentPermissionIds(array $ids): array
    {
        $selected = collect($ids)->map(fn ($id) => (int) $id)->unique()->values();
        $permissions = Permission::query()->whereIn('id', $selected->all() ?: [0])->get();
        $grouped = Permission::query()
            ->whereIn('group', $permissions->pluck('group')->unique()->filter()->all() ?: [''])
            ->get()
            ->groupBy('group');

        foreach ($grouped as $groupPermissions) {
            $view = $groupPermissions->first(fn (Permission $permission) => str_ends_with($permission->slug, '.view'));
            if ($view && ! $selected->contains($view->id)) {
                $selected = $selected->reject(fn ($id) => $groupPermissions->contains('id', $id))->values();
            }
        }

        return $selected->all();
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
