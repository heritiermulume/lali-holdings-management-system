<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
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
        return $this->ok(Role::query()->with('permissions')->orderBy('level')->get());
    }

    public function permissions(): JsonResponse
    {
        return $this->ok(Permission::query()->orderBy('group')->orderBy('slug')->get()->groupBy('group'));
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'permission_ids' => ['array'],
            'permission_ids.*' => ['exists:permissions,id'],
        ]);

        $role->fill($data)->save();

        if ($request->has('permission_ids')) {
            $role->permissions()->sync($request->input('permission_ids', []));
        }

        AuditLogger::record('update', $role, [], $data);

        return $this->ok($role->fresh('permissions'), __('Role updated.'));
    }
}
