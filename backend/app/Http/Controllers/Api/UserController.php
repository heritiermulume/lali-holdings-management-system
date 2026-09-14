<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'primaryCompany', 'primaryOffice']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $this->paginated($query->latest()->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['password'] = $data['password'] ?? 'Password123!';

        $user = User::query()->create($data);
        $user->roles()->sync($request->input('role_ids', []));
        $this->syncScopes($user, $request->input('scopes', []));

        AuditLogger::record('create', $user, [], $user->toArray());

        return $this->created($user->load(['roles', 'scopes']), __('User created.'));
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok($user->load(['roles.permissions', 'scopes', 'primaryCompany', 'primaryOffice']));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $old = $user->toArray();
        $data = $this->validated($request, $user->id);
        unset($data['password']);

        if ($request->filled('password')) {
            $data['password'] = $request->string('password')->toString();
        }

        $user->fill($data)->save();

        if ($request->has('role_ids')) {
            $user->roles()->sync($request->input('role_ids', []));
        }

        if ($request->has('scopes')) {
            $this->syncScopes($user, $request->input('scopes', []));
        }

        AuditLogger::record('update', $user, $old, $user->fresh()->toArray());

        return $this->ok($user->fresh(['roles', 'scopes']), __('User updated.'));
    }

    public function destroy(User $user): JsonResponse
    {
        abort_if($user->isSuperAdmin() && User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super_administrator'))->count() <= 1, 422, __('The last super administrator cannot be deleted.'));

        $user->delete();
        AuditLogger::record('delete', $user);

        return $this->ok(null, __('User deleted.'));
    }

    private function validated(Request $request, ?int $userId = null): array
    {
        return $request->validate([
            'first_name' => [$userId ? 'sometimes' : 'required', 'string', 'max:120'],
            'last_name' => [$userId ? 'sometimes' : 'required', 'string', 'max:120'],
            'email' => [$userId ? 'sometimes' : 'required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'in:fr,en'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['boolean'],
            'primary_company_id' => ['nullable', 'exists:companies,id'],
            'primary_office_id' => ['nullable', 'exists:offices,id'],
            'primary_department_id' => ['nullable', 'exists:departments,id'],
            'password' => [$userId ? 'nullable' : 'nullable', 'string', 'min:8'],
            'role_ids' => ['array'],
            'role_ids.*' => ['exists:roles,id'],
            'scopes' => ['array'],
        ]);
    }

    private function syncScopes(User $user, array $scopes): void
    {
        $user->scopes()->delete();

        foreach ($scopes as $scope) {
            if (! empty($scope['scope_type'])) {
                $user->scopes()->create([
                    'scope_type' => $scope['scope_type'],
                    'scope_id' => $scope['scope_id'] ?? null,
                ]);
            }
        }
    }
}
