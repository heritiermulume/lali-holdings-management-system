<?php

namespace App\Services;

use App\Mail\UserCredentialsMail;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserCredentialsService
{
    public function isAgent(User $user): bool
    {
        $user->loadMissing('roles');

        if ($user->isSuperAdmin() || $user->isGroupAdmin()) {
            return false;
        }

        return $user->roles->contains(fn (Role $role) => filled($role->pivot->company_id) || filled($role->pivot->office_id));
    }

    /**
     * @return array{email: string, phone: ?string, password: ?string, is_active: bool}|null
     */
    public function issueIfAgent(User $user, ?string $requestedPassword = null): ?array
    {
        $user->loadMissing('roles');

        if (! $this->isAgent($user)) {
            return null;
        }

        $newlyCreated = $user->created_at && $user->created_at->greaterThan(now()->subMinutes(2));
        $shouldIssue = filled($requestedPassword) || (blank($user->login_password) && $newlyCreated);

        if ($shouldIssue) {
            $plain = $requestedPassword ?: $this->generatePassword();
            $user->forceFill([
                'password' => $plain,
                'login_password' => $plain,
            ])->save();
            $this->syncKamsPassword($user, $plain);
            $this->sendTo($user, $plain);
        } elseif ($user->login_password) {
            $this->syncKamsPassword($user, $user->login_password);
        }

        return $this->payload($user->fresh());
    }

    /**
     * @return array{email: string, phone: ?string, password: ?string, is_active: bool}|null
     */
    public function payload(User $user): ?array
    {
        if (blank($user->login_password) && ! $this->isAgent($user)) {
            return null;
        }

        if (blank($user->login_password)) {
            return null;
        }

        return [
            'email' => $user->email,
            'phone' => $user->phone,
            'password' => $user->login_password,
            'is_active' => (bool) $user->is_active,
        ];
    }

    public function generatePassword(): string
    {
        return 'Lh-'.Str::upper(Str::random(4)).random_int(10, 99).'!';
    }

    public function sendTo(User $user, string $password, ?string $to = null): void
    {
        $recipient = $to ?: $user->email;
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::to($recipient)->send(new UserCredentialsMail($user, $password));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function syncKamsPassword(User $user, string $plain): void
    {
        KamsMember::query()
            ->where('user_id', $user->id)
            ->get()
            ->each(fn (KamsMember $member) => $member->forceFill(['login_password' => $plain])->save());
    }
}
