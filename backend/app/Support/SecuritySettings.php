<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rules\Password;

class SecuritySettings
{
    /**
     * Apply security settings from Administration → Security to runtime config.
     */
    public static function apply(): void
    {
        try {
            $minutes = (int) SettingsCatalog::number('security', 'session_lifetime_minutes', 120);
            $minutes = max(5, min(60 * 24 * 30, $minutes > 0 ? $minutes : 120));

            config([
                'sanctum.expiration' => $minutes,
                'session.lifetime' => $minutes,
            ]);
        } catch (\Throwable) {
            // DB may be unavailable during early boot / migrate.
        }
    }

    public static function passwordRule(): Password
    {
        $min = max(6, min(128, (int) SettingsCatalog::number('security', 'password_min_length', 10)));
        $rule = Password::min($min);
        if (SettingsCatalog::bool('security', 'password_require_mixed', true)) {
            $rule = $rule->mixedCase()->numbers()->symbols();
        }

        return $rule;
    }

    /**
     * Days until a password must be rotated. 0 = disabled.
     */
    public static function forcePasswordResetDays(): int
    {
        return max(0, min(3650, (int) SettingsCatalog::number('security', 'force_password_reset_days', 90)));
    }

    public static function mustChangePassword(User $user): bool
    {
        $days = self::forcePasswordResetDays();
        if ($days <= 0) {
            return false;
        }

        $changedAt = $user->password_changed_at;
        if (! $changedAt) {
            return true;
        }

        return $changedAt->lte(now()->subDays($days));
    }
}
