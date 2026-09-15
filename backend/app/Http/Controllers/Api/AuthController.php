<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use RespondsWithJson;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('These credentials do not match our records.')],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => __('This account is inactive.'),
            ], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('lhms-web')->plainTextToken;

        AuditLogger::record('login', $user);

        return $this->ok([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->presentUser($user),
        ], __('Signed in successfully.'));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($this->presentUser($request->user()));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['sometimes', 'in:fr,en'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'avatar' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ]);

        unset($data['avatar'], $data['remove_avatar']);

        $disk = Storage::disk('public');
        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                $disk->delete($user->avatar_path);
            }
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        } elseif ($request->boolean('remove_avatar')) {
            if ($user->avatar_path) {
                $disk->delete($user->avatar_path);
            }
            $data['avatar_path'] = null;
        }

        $user->fill($data)->save();
        AuditLogger::record('update', $user, [], $data);

        return $this->ok($this->presentUser($user->fresh()), __('Profile updated.'));
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $user = $request->user();
        $disk = Storage::disk('public');

        if ($user->avatar_path) {
            $disk->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->forceFill(['avatar_path' => $path])->save();
        AuditLogger::record('update', $user, [], ['avatar_path' => $path]);

        return $this->ok($this->presentUser($user->fresh()), __('Profile photo updated.'));
    }

    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $disk = Storage::disk('public');

        if ($user->avatar_path) {
            $oldPath = $user->avatar_path;
            $disk->delete($oldPath);
            $user->forceFill(['avatar_path' => null])->save();
            AuditLogger::record('update', $user, ['avatar_path' => $oldPath], ['avatar_path' => null]);
        }

        return $this->ok($this->presentUser($user->fresh()), __('Profile photo removed.'));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();
        KamsMember::query()
            ->where('user_id', $request->user()->id)
            ->update(['login_password' => null]);
        AuditLogger::record('password_change', $request->user());

        return $this->ok(null, __('Password updated.'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        AuditLogger::record('logout', $request->user());

        return $this->ok(null, __('Signed out.'));
    }

    private function presentUser(User $user): array
    {
        $user->load(['roles.permissions', 'scopes', 'primaryCompany', 'primaryOffice']);

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatar_url' => $user->avatar_path ? '/storage/'.$user->avatar_path : null,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
            'is_active' => $user->is_active,
            'primary_company' => $user->primaryCompany,
            'primary_office' => $user->primaryOffice,
            'roles' => $user->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'level' => $role->level,
                'module_slug' => $role->module_slug,
                'company_id' => $role->pivot->company_id ?? null,
            ]),
            'assignments' => $user->roleAssignmentPayload(),
            'permissions' => $user->permissionSlugs(),
            'scopes' => $user->scopes,
            'is_super_admin' => $user->isSuperAdmin(),
            'is_group_admin' => $user->isGroupAdmin(),
            'accessible_company_ids' => $user->accessibleCompanyIds(),
            'accessible_office_ids' => $user->accessibleOfficeIds(),
            'accessible_module_slugs' => $user->accessibleModuleSlugs(),
            'last_login_at' => $user->last_login_at,
        ];
    }
}
