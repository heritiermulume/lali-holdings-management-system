<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'primaryCompany', 'primaryOffice', 'scopes']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $page = $query->latest()->paginate($request->integer('per_page', 15));
        $page->getCollection()->transform(fn (User $user) => $this->present($user));

        return $this->paginated($page);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['password'] = $data['password'] ?? 'Password123!';
        $data['locale'] = $data['locale'] ?? SettingsCatalog::defaultLocale();
        $data['timezone'] = $data['timezone'] ?? (string) \App\Models\Setting::value('general', 'default_timezone', 'Africa/Kinshasa');
        unset($data['role_ids'], $data['scopes'], $data['company_ids'], $data['office_ids'], $data['assignments'], $data['platform_role_id']);

        $user = User::query()->create($data);
        $this->syncMemberships($user, $request);

        AuditLogger::record('create', $user, [], $user->toArray());

        return $this->created($this->present($user), __('User created.'));
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok($this->present($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $old = $user->toArray();
        $data = $this->validated($request, $user->id);
        unset($data['password'], $data['role_ids'], $data['scopes'], $data['company_ids'], $data['office_ids'], $data['assignments'], $data['platform_role_id']);

        if ($request->filled('password')) {
            $data['password'] = $request->string('password')->toString();
        }

        $this->assertPresidentCannotAffectSuperAdmin($request->user(), $user, $request);

        $user->fill($data)->save();

        if ($request->has('role_ids') || $request->has('assignments') || $request->has('platform_role_id') || $request->has('company_ids') || $request->has('office_ids') || $request->has('scopes')) {
            $this->syncMemberships($user, $request);
        }

        AuditLogger::record('update', $user, $old, $user->fresh()->toArray());

        return $this->ok($this->present($user->fresh()), __('User updated.'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->assertPresidentCannotAffectSuperAdmin($request->user(), $user, $request, 'delete');

        abort_if($user->isSuperAdmin() && User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super_administrator'))->count() <= 1, 422, __('The last super administrator cannot be deleted.'));

        $user->delete();
        AuditLogger::record('delete', $user);

        return $this->ok(null, __('User deleted.'));
    }

    private function assertPresidentCannotAffectSuperAdmin(?User $actor, User $target, Request $request, string $action = 'update'): void
    {
        if (! $target->isSuperAdmin() || ($actor && $actor->isSuperAdmin())) {
            return;
        }

        if ($action === 'delete') {
            abort(403, __('The president cannot delete or deactivate a super administrator.'));
        }

        if ($request->has('is_active') && ! $request->boolean('is_active')) {
            abort(403, __('The president cannot delete or deactivate a super administrator.'));
        }

        if ($request->has('role_ids') || $request->has('platform_role_id') || $request->has('assignments')) {
            $superId = Role::query()->where('slug', 'super_administrator')->value('id');
            $keepsSuper = collect($request->input('role_ids', []))->contains(fn ($id) => (int) $id === (int) $superId)
                || (int) $request->input('platform_role_id') === (int) $superId
                || collect($request->input('assignments', []))->contains(fn ($row) => (int) ($row['role_id'] ?? 0) === (int) $superId);
            abort_unless($keepsSuper, 403, __('The president cannot delete or deactivate a super administrator.'));
        }
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
            'company_ids' => ['array'],
            'company_ids.*' => ['exists:companies,id'],
            'office_ids' => ['array'],
            'office_ids.*' => ['exists:offices,id'],
            'platform_role_id' => ['nullable', 'exists:roles,id'],
            'assignments' => ['array'],
            'assignments.*.company_id' => ['required', 'exists:companies,id'],
            'assignments.*.role_id' => ['required', 'exists:roles,id'],
            'assignments.*.office_ids' => ['array'],
            'assignments.*.office_ids.*' => ['exists:offices,id'],
        ]);
    }

    private function syncMemberships(User $user, Request $request): void
    {
        $rows = [];
        $scopes = [];
        $companyIds = collect();
        $officeIds = collect();

        $platformRoleId = $request->input('platform_role_id');
        if ($platformRoleId) {
            $platform = Role::query()->findOrFail((int) $platformRoleId);
            abort_unless($platform->hasFullAccess(), 422, __('Only Super Administrator and President can be assigned outside a module.'));
            $rows[] = ['role_id' => $platform->id, 'company_id' => null];
        }

        if ($request->has('assignments')) {
            $seenCompanies = [];
            foreach ($request->input('assignments', []) as $assignment) {
                $companyId = (int) ($assignment['company_id'] ?? 0);
                $roleId = (int) ($assignment['role_id'] ?? 0);
                if (! $companyId || ! $roleId) {
                    continue;
                }
                abort_if(isset($seenCompanies[$companyId]), 422, __('A user can only have one role per company.'));
                $seenCompanies[$companyId] = true;

                $company = Company::query()->with('module')->findOrFail($companyId);
                $role = Role::query()->findOrFail($roleId);
                abort_if($role->hasFullAccess(), 422, __('Platform roles cannot be tied to a company.'));
                abort_unless($role->module_slug && $company->module && $role->module_slug === $company->module->slug, 422, __('The role must belong to the same module as the company.'));

                $rows[] = ['role_id' => $role->id, 'company_id' => $company->id];
                $companyIds->push($company->id);
                $scopes[] = ['scope_type' => 'company', 'scope_id' => $company->id];
                if ($company->system_module_id) {
                    $scopes[] = ['scope_type' => 'module', 'scope_id' => $company->system_module_id];
                }

                foreach (collect($assignment['office_ids'] ?? [])->filter()->map(fn ($id) => (int) $id) as $officeId) {
                    $officeCompanyId = Office::query()->whereKey($officeId)->value('company_id');
                    abort_unless((int) $officeCompanyId === $company->id, 422, __('Offices must belong to the selected company.'));
                    $officeIds->push($officeId);
                    $scopes[] = ['scope_type' => 'office', 'scope_id' => $officeId];
                }
            }
        } elseif ($request->has('role_ids') || $request->has('company_ids')) {
            $legacyRoleId = collect($request->input('role_ids', []))->filter()->map(fn ($id) => (int) $id)->first();
            $legacyCompanies = collect($request->input('company_ids', []))->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $legacyOffices = collect($request->input('office_ids', []))->filter()->map(fn ($id) => (int) $id)->unique()->values();

            if ($legacyRoleId) {
                $role = Role::query()->findOrFail($legacyRoleId);
                if ($role->hasFullAccess()) {
                    $rows[] = ['role_id' => $role->id, 'company_id' => null];
                } else {
                    foreach ($legacyCompanies as $companyId) {
                        $rows[] = ['role_id' => $role->id, 'company_id' => $companyId];
                    }
                }
            }

            foreach ($legacyCompanies as $companyId) {
                $companyIds->push($companyId);
                $scopes[] = ['scope_type' => 'company', 'scope_id' => $companyId];
                $moduleId = Company::query()->whereKey($companyId)->value('system_module_id');
                if ($moduleId) {
                    $scopes[] = ['scope_type' => 'module', 'scope_id' => $moduleId];
                }
            }
            foreach ($legacyOffices as $officeId) {
                $officeIds->push($officeId);
                $scopes[] = ['scope_type' => 'office', 'scope_id' => $officeId];
            }
        }

        $user->syncRoleAssignments($rows);
        $this->syncScopes($user, $scopes);

        if (! $request->filled('primary_company_id')) {
            $user->primary_company_id = $companyIds->first();
        }
        if (! $request->filled('primary_office_id')) {
            $user->primary_office_id = $officeIds->first();
        }
        $user->save();
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

    private function present(User $user): array
    {
        $user->load(['roles', 'scopes', 'primaryCompany.module', 'primaryOffice']);

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'primary_company' => $user->primaryCompany,
            'primary_office' => $user->primaryOffice,
            'roles' => $user->roles,
            'assignments' => $user->roleAssignmentPayload(),
            'scopes' => $user->scopes,
        ];
    }
}
