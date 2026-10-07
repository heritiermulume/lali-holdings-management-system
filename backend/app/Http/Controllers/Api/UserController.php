<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\RoleCatalog;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kams\Services\KamsAccountProvisioner;
use App\Modules\Kams\Support\SpreadsheetRows;
use App\Services\UserCredentialsService;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly KamsAccountProvisioner $kamsAccounts,
        private readonly UserCredentialsService $credentials,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = User::query()->with(['roles', 'primaryCompany', 'primaryOffice', 'scopes']);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('company_id')) {
            $companyId = $request->integer('company_id');
            $query->where(function ($q) use ($companyId) {
                $q->where('primary_company_id', $companyId)
                    ->orWhereHas('roles', fn ($roles) => $roles->where('role_user.company_id', $companyId));
            });
        }

        if ($request->filled('role_id')) {
            $roleId = $request->integer('role_id');
            $query->whereHas('roles', fn ($roles) => $roles->where('roles.id', $roleId));
        }

        if ($request->exists('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $page = $query->latest()->paginate($request->integer('per_page', 50));
        $page->getCollection()->transform(fn (User $user) => $this->present($user));

        return $this->paginated($page, 'OK', [
            'summary' => $this->summary(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeMembershipPayload($request);
        $data = $this->validated($request);
        $this->assertHasAssignment($request);
        $requestedPassword = $request->filled('password') ? $request->string('password')->toString() : null;
        $data['password'] = $requestedPassword ?: 'Password123!';
        $data['locale'] = $data['locale'] ?? SettingsCatalog::defaultLocale();
        $data['timezone'] = $data['timezone'] ?? SettingsCatalog::string('general', 'default_timezone');
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $request->boolean('is_active');
        }
        unset($data['role_ids'], $data['scopes'], $data['company_ids'], $data['office_ids'], $data['assignments'], $data['platform_role_id'], $data['avatar'], $data['remove_avatar']);

        $user = DB::transaction(function () use ($request, $data, $requestedPassword) {
            $user = User::query()->create($data);
            $this->syncAvatar($request, $user);
            $this->syncMemberships($user, $request);
            $this->kamsAccounts->tryForUser($user->fresh('roles'));
            $this->credentials->issueIfAgent($user->fresh('roles'), $requestedPassword);

            return $user->fresh();
        });

        return $this->created($this->present($user), __('User created.'));
    }

    public function show(User $user): JsonResponse
    {
        return $this->ok($this->present($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->normalizeMembershipPayload($request);
        $old = $user->toArray();
        $data = $this->validated($request, $user->id);
        unset($data['password'], $data['role_ids'], $data['scopes'], $data['company_ids'], $data['office_ids'], $data['assignments'], $data['platform_role_id'], $data['avatar'], $data['remove_avatar']);

        $requestedPassword = $request->filled('password') ? $request->string('password')->toString() : null;
        if ($requestedPassword) {
            $data['password'] = $requestedPassword;
        }
        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $this->assertCannotAffectSuperAdmin($request->user(), $user, $request);

        $user = DB::transaction(function () use ($request, $user, $data, $requestedPassword) {
            $user->fill($data)->save();
            $this->syncAvatar($request, $user);

            if ($request->has('role_ids') || $request->has('assignments') || $request->has('platform_role_id') || $request->has('company_ids') || $request->has('office_ids') || $request->has('scopes')) {
                $this->assertHasAssignment($request);
                $this->syncMemberships($user, $request);
            }

            $this->kamsAccounts->tryForUser($user->fresh('roles'));
            $this->credentials->issueIfAgent($user->fresh('roles'), $requestedPassword);

            return $user->fresh();
        });

        return $this->ok($this->present($user), __('User updated.'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($request->user()?->id === $user->id, 422, __('You cannot delete your own account.'));
        abort_if($user->isSuperAdmin(), 422, __('A super administrator cannot be deleted.'));

        $user->delete();

        return $this->ok(null, __('User deleted.'));
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'max:10240'],
            'rows' => ['nullable', 'array'],
            'rows.*' => ['array'],
        ]);

        $rows = $data['rows'] ?? [];
        if ($request->hasFile('file')) {
            $rows = SpreadsheetRows::fromUpload($request->file('file'));
        }

        abort_if($rows === [], 422, __('Add an Excel/CSV file or paste rows to import.'));

        $created = [];
        $errors = [];

        foreach (array_values($rows) as $index => $row) {
            $payload = is_array($row) ? $row : [];
            try {
                $created[] = DB::transaction(fn () => $this->importRow($request, $payload));
            } catch (\Throwable $exception) {
                $errors[] = [
                    'row' => $index + 2,
                    'email' => $this->rowValue($payload, ['email', 'e-mail', 'mail']) ?: null,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $this->ok([
            'created' => $created,
            'errors' => $errors,
            'imported' => count($created),
            'skipped' => count($errors),
        ], __('Users imported.'));
    }

    private function assertCannotAffectSuperAdmin(?User $actor, User $target, Request $request): void
    {
        if (! $target->isSuperAdmin() || ($actor && $actor->isSuperAdmin())) {
            return;
        }

        if ($request->has('is_active') && ! $request->boolean('is_active')) {
            abort(403, __('Only a super administrator can delete or deactivate another super administrator.'));
        }

        if ($request->has('role_ids') || $request->has('platform_role_id') || $request->has('assignments')) {
            $superId = Role::query()->where('slug', 'super_administrator')->value('id');
            $keepsSuper = collect($request->input('role_ids', []))->contains(fn ($id) => (int) $id === (int) $superId)
                || (int) $request->input('platform_role_id') === (int) $superId
                || collect($request->input('assignments', []))->contains(function ($row) use ($superId) {
                    if ((int) ($row['role_id'] ?? 0) === (int) $superId) {
                        return true;
                    }

                    return collect($row['offices'] ?? [])->contains(fn ($office) => (int) ($office['role_id'] ?? 0) === (int) $superId);
                });
            abort_unless($keepsSuper, 403, __('Only a super administrator can delete or deactivate another super administrator.'));
        }
    }

    private function validated(Request $request, ?int $userId = null): array
    {
        if ($request->input('gender') === '') {
            $request->merge(['gender' => null]);
        }

        return $request->validate([
            'first_name' => [$userId ? 'sometimes' : 'required', 'string', 'max:120'],
            'last_name' => [$userId ? 'sometimes' : 'required', 'string', 'max:120'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'email' => [$userId ? 'sometimes' : 'required', 'email', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'in:fr,en'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'is_active' => ['boolean'],
            'primary_company_id' => ['nullable', 'exists:companies,id'],
            'primary_office_id' => ['nullable', 'exists:offices,id'],
            'password' => ['nullable', 'string', Password::defaults()],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'remove_avatar' => ['sometimes', 'boolean'],
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
            'assignments.*.role_id' => ['nullable', 'exists:roles,id'],
            'assignments.*.office_ids' => ['array'],
            'assignments.*.office_ids.*' => ['exists:offices,id'],
            'assignments.*.offices' => ['array'],
            'assignments.*.offices.*.office_id' => ['required', 'exists:offices,id'],
            'assignments.*.offices.*.role_id' => ['required', 'exists:roles,id'],
        ]);
    }

    private function normalizeMembershipPayload(Request $request): void
    {
        $assignments = $request->input('assignments');
        if (is_string($assignments)) {
            $decoded = json_decode($assignments, true);
            $request->merge(['assignments' => is_array($decoded) ? $decoded : []]);
        }

        if ($request->exists('platform_role_id') && ! $request->filled('platform_role_id')) {
            $request->merge(['platform_role_id' => null]);
        }
    }

    private function assertHasAssignment(Request $request): void
    {
        if ($request->filled('platform_role_id') || $request->filled('role_ids')) {
            return;
        }

        $hasMembership = collect($request->input('assignments', []))->contains(function ($row) {
            if ((int) ($row['company_id'] ?? 0) < 1) {
                return false;
            }

            if ((int) ($row['role_id'] ?? 0) > 0) {
                return true;
            }

            return collect($row['offices'] ?? [])->contains(fn ($office) => (int) ($office['office_id'] ?? 0) && (int) ($office['role_id'] ?? 0))
                || collect($row['office_ids'] ?? [])->contains(fn ($id) => (int) $id > 0);
        });

        abort_unless($hasMembership, 422, __('Choose a system-wide role, or a company with a role in the company or an office.'));
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
            abort_if($platform->slug === 'super_administrator' && ! $request->user()?->isSuperAdmin(), 403, __('Only a super administrator can assign the Super Administrator role.'));
            $rows[] = ['role_id' => $platform->id, 'company_id' => null, 'office_id' => null];
        }

        if ($request->has('assignments')) {
            $seenCompanies = [];
            foreach ($request->input('assignments', []) as $assignment) {
                $companyId = (int) ($assignment['company_id'] ?? 0);
                if (! $companyId) {
                    continue;
                }
                abort_if(isset($seenCompanies[$companyId]), 422, __('A user can only have one membership per company.'));
                $seenCompanies[$companyId] = true;

                $company = Company::query()->with('module')->findOrFail($companyId);
                $companyIds->push($company->id);
                $scopes[] = ['scope_type' => 'company', 'scope_id' => $company->id];
                if ($company->system_module_id) {
                    $scopes[] = ['scope_type' => 'module', 'scope_id' => $company->system_module_id];
                }

                $companyRoleId = (int) ($assignment['role_id'] ?? 0);
                $officeAssignments = collect($assignment['offices'] ?? [])
                    ->map(fn ($item) => [
                        'office_id' => (int) ($item['office_id'] ?? 0),
                        'role_id' => (int) ($item['role_id'] ?? 0),
                    ])
                    ->filter(fn ($item) => $item['office_id'] && $item['role_id'])
                    ->values();

                if ($officeAssignments->isEmpty() && $companyRoleId) {
                    $legacyOfficeIds = collect($assignment['office_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->unique()->values();
                    if ($legacyOfficeIds->isNotEmpty()) {
                        $legacyRole = Role::query()->findOrFail($companyRoleId);
                        $this->assertModuleRole($legacyRole, $company);
                        if ($legacyRole->isOfficeScoped()) {
                            $officeAssignments = $legacyOfficeIds->map(fn ($officeId) => [
                                'office_id' => $officeId,
                                'role_id' => $companyRoleId,
                            ]);
                            $companyRoleId = 0;
                        } else {
                            foreach ($legacyOfficeIds as $officeId) {
                                $officeCompanyId = Office::query()->whereKey($officeId)->value('company_id');
                                abort_unless((int) $officeCompanyId === $company->id, 422, __('Offices must belong to the selected company.'));
                                $officeIds->push($officeId);
                                $scopes[] = ['scope_type' => 'office', 'scope_id' => $officeId];
                            }
                        }
                    }
                }

                if ($companyRoleId) {
                    $role = Role::query()->findOrFail($companyRoleId);
                    $this->assertModuleRole($role, $company);
                    if (! ($role->isOfficeScoped() && $officeAssignments->isNotEmpty())) {
                        $rows[] = ['role_id' => $role->id, 'company_id' => $company->id, 'office_id' => null];
                    }
                }

                $seenOffices = [];
                foreach ($officeAssignments as $officeAssignment) {
                    $officeId = $officeAssignment['office_id'];
                    abort_if(isset($seenOffices[$officeId]), 422, __('A user can only have one role per office.'));
                    $seenOffices[$officeId] = true;

                    $officeCompanyId = Office::query()->whereKey($officeId)->value('company_id');
                    abort_unless((int) $officeCompanyId === $company->id, 422, __('Offices must belong to the selected company.'));

                    $officeRole = Role::query()->findOrFail($officeAssignment['role_id']);
                    $this->assertModuleRole($officeRole, $company);

                    $rows[] = ['role_id' => $officeRole->id, 'company_id' => $company->id, 'office_id' => $officeId];
                    $officeIds->push($officeId);
                    $scopes[] = ['scope_type' => 'office', 'scope_id' => $officeId];
                }

                abort_unless($companyRoleId || $officeAssignments->isNotEmpty(), 422, __('Choose a role for the company or for at least one office.'));
            }
        } elseif ($request->has('role_ids') || $request->has('company_ids')) {
            $legacyRoleId = collect($request->input('role_ids', []))->filter()->map(fn ($id) => (int) $id)->first();
            $legacyCompanies = collect($request->input('company_ids', []))->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $legacyOffices = collect($request->input('office_ids', []))->filter()->map(fn ($id) => (int) $id)->unique()->values();

            if ($legacyRoleId) {
                $role = Role::query()->findOrFail($legacyRoleId);
                if ($role->hasFullAccess()) {
                    $rows[] = ['role_id' => $role->id, 'company_id' => null, 'office_id' => null];
                } else {
                    foreach ($legacyCompanies as $companyId) {
                        $rows[] = ['role_id' => $role->id, 'company_id' => $companyId, 'office_id' => null];
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

    private function assertModuleRole(Role $role, Company $company): void
    {
        abort_if($role->hasFullAccess(), 422, __('Platform roles cannot be tied to a company.'));
        abort_unless($role->module_slug && $company->module && $role->module_slug === $company->module->slug, 422, __('The role must belong to the same module as the company.'));
    }

    private function syncAvatar(Request $request, User $user): void
    {
        $disk = Storage::disk('public');

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                $disk->delete($user->avatar_path);
            }
            $user->forceFill([
                'avatar_path' => $request->file('avatar')->store('avatars', 'public'),
            ])->save();

            return;
        }

        if ($request->boolean('remove_avatar')) {
            if ($user->avatar_path) {
                $disk->delete($user->avatar_path);
            }
            $user->forceFill(['avatar_path' => null])->save();
        }
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

    /**
     * @return array{total: int, active: int, male: int, female: int, unspecified: int}
     */
    private function summary(): array
    {
        $total = User::query()->count();
        $active = User::query()->where('is_active', true)->count();
        $male = User::query()->where('gender', 'male')->count();
        $female = User::query()->where('gender', 'female')->count();

        return [
            'total' => $total,
            'active' => $active,
            'male' => $male,
            'female' => $female,
            'unspecified' => max(0, $total - $male - $female),
        ];
    }

    private function present(User $user): array
    {
        $user->load(['roles', 'scopes', 'primaryCompany.module', 'primaryOffice']);

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'gender' => $user->gender,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_path ? '/storage/'.$user->avatar_path : null,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'primary_company' => $user->primaryCompany,
            'primary_office' => $user->primaryOffice,
            'roles' => $user->roles,
            'assignments' => $user->roleAssignmentPayload(),
            'scopes' => $user->scopes,
            'account' => $this->credentials->payload($user),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function importRow(Request $request, array $row): array
    {
        $firstName = $this->rowValue($row, ['first_name', 'prenom', 'prénom']);
        $lastName = $this->rowValue($row, ['last_name', 'nom']);
        $email = strtolower($this->rowValue($row, ['email', 'e-mail', 'mail']));
        $phone = $this->rowValue($row, ['phone', 'telephone', 'téléphone']) ?: null;
        $genderRaw = strtolower($this->rowValue($row, ['gender', 'sexe', 'sex']));
        $gender = match ($genderRaw) {
            'male', 'm', 'homme', 'h' => 'male',
            'female', 'f', 'femme' => 'female',
            default => null,
        };
        $platformRole = $this->rowValue($row, ['platform_role', 'role_transversal', 'rôle_transversal']);
        $companyValue = $this->rowValue($row, ['company', 'societe', 'société', 'company_code']);
        $roleValue = $this->rowValue($row, ['role', 'rôle', 'membership_role']);
        $officesValue = $this->rowValue($row, ['offices', 'bureaux', 'office', 'office_codes']);
        $activeValue = strtolower($this->rowValue($row, ['is_active', 'actif', 'status', 'statut']));

        abort_unless($firstName && $lastName && $email, 422, __('First name, last name and email are required.'));
        abort_if(User::query()->where('email', $email)->exists(), 422, __('A user with this email already exists.'));

        $isActive = ! in_array($activeValue, ['0', 'false', 'inactive', 'inactif', 'no', 'non'], true);

        $user = User::query()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => $gender,
            'email' => $email,
            'phone' => $phone,
            'password' => 'Password123!',
            'locale' => SettingsCatalog::defaultLocale(),
            'timezone' => SettingsCatalog::string('general', 'default_timezone'),
            'is_active' => $isActive,
        ]);

        $payload = new Request;
        $payload->setUserResolver(fn () => $request->user());

        if ($platformRole) {
            $role = $this->resolveRole($platformRole);
            abort_unless($role?->hasFullAccess(), 422, __('The platform role is invalid.'));
            abort_if($role->slug === 'super_administrator' && ! $request->user()?->isSuperAdmin(), 403, __('Only a super administrator can assign the Super Administrator role.'));
            $payload->merge(['platform_role_id' => $role->id, 'assignments' => []]);
        } else {
            abort_unless($companyValue && $roleValue, 422, __('Company and role are required when no platform role is set.'));
            $company = $this->resolveCompany($companyValue);
            abort_unless($company, 422, __('The company could not be found.'));
            $role = $this->resolveRole($roleValue);
            abort_unless($role, 422, __('The role could not be found.'));
            abort_if($role->hasFullAccess(), 422, __('Platform roles cannot be tied to a company.'));
            $officeIds = $this->resolveOfficeIds($company->id, $officesValue);
            $payload->merge([
                'platform_role_id' => null,
                'assignments' => [[
                    'company_id' => $company->id,
                    'role_id' => $role->id,
                    'offices' => collect($officeIds)->map(fn ($id) => [
                        'office_id' => $id,
                        'role_id' => $role->id,
                    ])->all(),
                ]],
            ]);
        }

        $this->syncMemberships($user, $payload);
        $this->kamsAccounts->tryForUser($user->fresh('roles'));
        $this->credentials->issueIfAgent($user->fresh('roles'));

        return $this->present($user->fresh());
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function rowValue(array $row, array $keys): string
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[strtolower(trim((string) $key))] = trim((string) $value);
        }

        foreach ($keys as $key) {
            $value = $normalized[strtolower($key)] ?? '';
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveRole(string $value): ?Role
    {
        $slug = RoleCatalog::resolve($value) ?? strtolower(str_replace(['-', ' '], '_', trim($value)));

        return Role::query()
            ->where(function ($query) use ($slug, $value) {
                $query->where('slug', $slug)
                    ->orWhereRaw('lower(name) = ?', [mb_strtolower(trim($value))]);
            })
            ->first();
    }

    private function resolveCompany(string $value): ?Company
    {
        return Company::query()
            ->where(function ($query) use ($value) {
                $query->where('code', $value)
                    ->orWhere('name', $value)
                    ->orWhere('legal_name', $value);
            })
            ->first();
    }

    /**
     * @return list<int>
     */
    private function resolveOfficeIds(int $companyId, string $value): array
    {
        if ($value === '') {
            return [];
        }

        $tokens = collect(preg_split('/[|;,]+/', $value) ?: [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values();

        if ($tokens->isEmpty()) {
            return [];
        }

        return Office::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($tokens) {
                $query->whereIn('code', $tokens)->orWhereIn('name', $tokens);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
