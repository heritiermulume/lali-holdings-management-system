<?php

namespace App\Modules\Kams\Services;

use App\Core\Support\RoleCatalog;
use App\Models\Company;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Services\UserCredentialsService;
use App\Support\SettingsCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AgentAccountService
{
    public function __construct(private readonly UserCredentialsService $userCredentials) {}

    /**
     * @return array{email: string, phone: ?string, password: ?string, is_active: bool}
     */
    public function provision(KamsMember $member): array
    {
        $member->refresh();

        if ($member->user_id) {
            return $this->ensureCredentials($member, $member->user()->firstOrFail());
        }

        $preferred = $this->preferredEmail($member);
        if ($preferred) {
            $existing = User::query()->where('email', $preferred)->first();
            if ($existing) {
                $member->forceFill(['user_id' => $existing->id])->save();

                return $this->ensureCredentials($member, $existing);
            }
        }

        $password = $this->userCredentials->generatePassword();
        $email = $this->loginEmail($member);

        $user = User::query()->create([
            'first_name' => $member->first_name ?: Str::of($member->full_name)->before(' ')->toString() ?: $member->full_name,
            'last_name' => $member->last_name ?: Str::of($member->full_name)->after(' ')->toString() ?: $member->member_code,
            'email' => $email,
            'phone' => $member->phone,
            'avatar_path' => $member->photo_path,
            'locale' => SettingsCatalog::defaultLocale(),
            'timezone' => (string) Setting::getValue('general', 'default_timezone', 'Africa/Kinshasa'),
            'is_active' => true,
            'primary_company_id' => $member->company_id,
            'primary_office_id' => $member->office_id,
            'password' => $password,
        ]);

        $this->syncRoles($member, $user);
        $this->syncScopes($member, $user);

        $user->forceFill(['login_password' => $password])->save();

        $member->forceFill([
            'user_id' => $user->id,
            'login_password' => $password,
        ])->save();

        $this->queueCredentialsMail($user, $password, $this->mailRecipient($member, $email));

        return $this->credentials($member->fresh('user'), true);
    }

    /**
     * @return array{email: string, password: ?string, is_active: bool}
     */
    public function setActive(KamsMember $member, bool $active): array
    {
        $this->provision($member);
        $member->refresh();
        $user = $member->user()->firstOrFail();
        $user->forceFill(['is_active' => $active])->save();

        if (! $active) {
            $user->tokens()->delete();
        }

        return $this->credentials($member->fresh('user'), $active);
    }

    public function syncFromMember(KamsMember $member): void
    {
        if (! $member->user_id) {
            return;
        }

        $user = $member->user()->first();
        if (! $user) {
            return;
        }

        $this->syncUser($member, $user);
    }

    public function deactivate(KamsMember $member): void
    {
        if (! $member->user_id) {
            return;
        }

        $user = $member->user()->first();
        if (! $user) {
            return;
        }

        $user->forceFill(['is_active' => false])->save();
        $user->tokens()->delete();
    }

    /**
     * @return array{email: string, phone: ?string, password: ?string, is_active: bool}
     */
    public function credentials(KamsMember $member, ?bool $isActive = null): array
    {
        $user = $member->relationLoaded('user') ? $member->user : $member->user()->first();

        return [
            'email' => $user?->email ?? $member->email,
            'phone' => $user?->phone ?? $member->phone,
            'password' => $member->login_password,
            'is_active' => $isActive ?? (bool) $user?->is_active,
        ];
    }

    /**
     * @return array{email: string, phone: ?string, password: ?string, is_active: bool}
     */
    private function ensureCredentials(KamsMember $member, User $user): array
    {
        $this->syncUser($member, $user);
        $user->refresh();

        $plain = $user->login_password ?: $member->login_password;

        if (blank($plain)) {
            $plain = $this->userCredentials->generatePassword();
            $user->forceFill([
                'password' => $plain,
                'login_password' => $plain,
                'is_active' => true,
            ])->save();
            $member->forceFill([
                'user_id' => $user->id,
                'login_password' => $plain,
            ])->save();
            $this->queueCredentialsMail($user, $plain, $this->mailRecipient($member, $user->email));

            return $this->credentials($member->fresh('user'), true);
        }

        $user->forceFill(['login_password' => $plain])->save();
        $member->forceFill([
            'user_id' => $user->id,
            'login_password' => $plain,
        ])->save();

        return $this->credentials($member->fresh('user'));
    }

    private function queueCredentialsMail(User $user, string $password, ?string $to): void
    {
        $userId = $user->id;
        $send = function () use ($userId, $password, $to) {
            $fresh = User::query()->find($userId);
            if (! $fresh) {
                return;
            }

            $this->userCredentials->sendTo($fresh, $password, $to);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }
    }

    private function syncUser(KamsMember $member, User $user): void
    {
        $email = $user->email;
        $preferred = $this->preferredEmail($member);
        if ($preferred && $preferred !== $user->email && ! $this->emailTaken($preferred, $user->id)) {
            $email = $preferred;
        }

        $user->forceFill([
            'first_name' => $member->first_name ?: $user->first_name,
            'last_name' => $member->last_name ?: $user->last_name,
            'email' => $email,
            'phone' => $member->phone ?: $user->phone,
            'primary_company_id' => $member->company_id,
            'primary_office_id' => $member->office_id,
        ])->save();

        $this->syncRoles($member, $user);
        $this->syncScopes($member, $user);
    }

    private function syncRoles(KamsMember $member, User $user): void
    {
        $role = $member->role_id ? Role::query()->find($member->role_id) : null;
        $blocked = RoleCatalog::fullAccessSlugs();

        if (! $role || in_array($role->slug, $blocked, true)) {
            $roleId = Role::query()->where('slug', 'distributor')->value('id');
        } else {
            $roleId = $role->id;
        }

        if ($roleId) {
            $user->assignCompanyRole((int) $roleId, (int) $member->company_id, $member->office_id ? (int) $member->office_id : null);
        }
    }

    private function syncScopes(KamsMember $member, User $user): void
    {
        $user->scopes()->delete();

        if ($member->company_id) {
            $user->scopes()->create(['scope_type' => 'company', 'scope_id' => $member->company_id]);
            $moduleId = Company::query()->whereKey($member->company_id)->value('system_module_id');
            if ($moduleId) {
                $user->scopes()->create(['scope_type' => 'module', 'scope_id' => $moduleId]);
            }
        }

        if ($member->office_id) {
            $user->scopes()->create(['scope_type' => 'office', 'scope_id' => $member->office_id]);
        }
    }

    private function loginEmail(KamsMember $member): string
    {
        $preferred = $this->preferredEmail($member);
        if ($preferred && ! $this->emailTaken($preferred)) {
            return $preferred;
        }

        $base = strtolower((string) preg_replace('/[^a-z0-9]+/i', '.', $member->member_code));
        $base = trim($base, '.') ?: 'agent.'.$member->id;
        $email = $base.'@kams.lali';
        $suffix = 1;

        while ($this->emailTaken($email)) {
            $email = $base.$suffix.'@kams.lali';
            $suffix++;
        }

        return $email;
    }

    private function mailRecipient(KamsMember $member, string $loginEmail): ?string
    {
        if ($preferred = $this->preferredEmail($member)) {
            return $preferred;
        }

        $loginEmail = strtolower(trim($loginEmail));
        if ($loginEmail === '' || str_ends_with($loginEmail, '@kams.lali') || ! filter_var($loginEmail, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $loginEmail;
    }

    private function preferredEmail(KamsMember $member): ?string
    {
        $email = strtolower(trim((string) $member->email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function emailTaken(string $email, ?int $ignoreUserId = null): bool
    {
        return User::query()
            ->where('email', $email)
            ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
            ->exists();
    }
}
