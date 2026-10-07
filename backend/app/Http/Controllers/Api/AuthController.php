<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use RespondsWithJson;

    public function login(Request $request): JsonResponse
    {
        $login = trim((string) ($request->input('login') ?? $request->input('email') ?? ''));
        $request->merge(['login' => $login]);
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->userByLogin($login);

        if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => [__('These credentials do not match our records.')],
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
        }

        return $this->ok($this->presentUser($user->fresh()), __('Profile photo removed.'));
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => $data['password'],
            'login_password' => null,
        ])->save();
        KamsMember::query()
            ->where('user_id', $request->user()->id)
            ->update(['login_password' => null]);

        return $this->ok(null, __('Password updated.'));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();
        if ($user && $user->is_active) {
            try {
                PasswordBroker::sendResetLink(['email' => $user->email]);
            } catch (\Throwable $exception) {
                report($exception);

                return response()->json([
                    'success' => false,
                    'message' => __('Unable to send the reset link.'),
                ], 500);
            }
        }

        return $this->ok(null, __('A password reset email has been sent to you.'));
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $status = PasswordBroker::reset(
            $data,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'login_password' => null,
                ])->save();

                KamsMember::query()
                    ->where('user_id', $user->id)
                    ->update(['login_password' => null]);

                $user->tokens()->delete();
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->ok(null, __('Your password has been reset.'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(null, __('Signed out.'));
    }

    private function userByLogin(string $login): ?User
    {
        $user = User::query()->where('email', $login)->first();
        if ($user) {
            return $user;
        }

        $match = User::query()->where('phone', $login)->first();
        if ($match) {
            return $match;
        }

        $digits = preg_replace('/\D+/', '', $login) ?: '';
        if ($digits === '') {
            return null;
        }

        return User::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '.', ''), '/', '') = ?",
                [$digits]
            )
            ->first();
    }

    private function presentUser(User $user): array
    {
        $user->load(['roles.permissions', 'scopes', 'primaryCompany', 'primaryOffice']);
        SettingsCatalog::ensure();

        $gender = $user->gender;
        if (! in_array($gender, ['male', 'female'], true)) {
            $gender = KamsMember::query()
                ->where('user_id', $user->id)
                ->value('gender');
        }

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'gender' => in_array($gender, ['male', 'female'], true) ? $gender : null,
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
                'office_id' => $role->pivot->office_id ?? null,
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
            'features' => (object) [],
            'preferences' => [
                'decimal_places' => max(0, min(6, (int) SettingsCatalog::number('finance', 'decimal_places'))),
                'date_format' => SettingsCatalog::string('general', 'date_format'),
                'timezone' => (string) ($user->timezone ?: SettingsCatalog::string('general', 'default_timezone')),
                'group_name' => SettingsCatalog::string('general', 'group_name'),
                'legal_name' => SettingsCatalog::string('general', 'legal_name'),
                'support_email' => SettingsCatalog::string('general', 'support_email'),
                'reporting_currency' => Currency::defaultCode(),
            ],
        ];
    }
}
