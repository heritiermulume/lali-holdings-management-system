<?php

namespace App\Support;

use App\Models\Setting;

class SettingsCatalog
{
    /**
     * @return array<string, array<string, array{type: string, default: string}>>
     */
    public static function all(): array
    {
        return [
            'general' => [
                'group_name' => ['type' => 'string', 'default' => 'LALI Holdings'],
                'legal_name' => ['type' => 'string', 'default' => 'LALI Holdings SAS'],
                'support_email' => ['type' => 'string', 'default' => 'samuel.w@example.com'],
                'default_locale' => ['type' => 'string', 'default' => 'fr'],
                'default_timezone' => ['type' => 'string', 'default' => 'Africa/Kinshasa'],
                'date_format' => ['type' => 'string', 'default' => 'DD/MM/YYYY'],
                'week_start' => ['type' => 'string', 'default' => '1'],
                'mail_from_address' => ['type' => 'string', 'default' => 'samuel.w@example.com'],
                'mail_from_name' => ['type' => 'string', 'default' => 'LALI Holdings'],
                'mail_host' => ['type' => 'string', 'default' => ''],
                'mail_port' => ['type' => 'number', 'default' => '587'],
                'mail_username' => ['type' => 'string', 'default' => ''],
                'mail_password' => ['type' => 'string', 'default' => ''],
                'mail_encryption' => ['type' => 'string', 'default' => 'tls'],
            ],
            'finance' => [
                'decimal_places' => ['type' => 'number', 'default' => '2'],
                'fiscal_year_start_month' => ['type' => 'number', 'default' => '1'],
                'fx_spread_percent' => ['type' => 'number', 'default' => '1.5'],
                'reporting_currency' => ['type' => 'string', 'default' => 'USD'],
                'multi_currency' => ['type' => 'boolean', 'default' => '1'],
                'tax_name' => ['type' => 'string', 'default' => 'TVA'],
                'default_tax_rate' => ['type' => 'number', 'default' => '16'],
            ],
            'security' => [
                'password_min_length' => ['type' => 'number', 'default' => '10'],
                'password_require_mixed' => ['type' => 'boolean', 'default' => '1'],
                'session_lifetime_minutes' => ['type' => 'number', 'default' => '120'],
                'force_password_reset_days' => ['type' => 'number', 'default' => '90'],
            ],
            'kams' => [
                'order_prefix' => ['type' => 'string', 'default' => 'KA'],
                'invoice_prefix' => ['type' => 'string', 'default' => 'KAF'],
                'stock_alert_threshold' => ['type' => 'number', 'default' => '5'],
                'default_payment_terms_days' => ['type' => 'number', 'default' => '30'],
                'allow_backorders' => ['type' => 'boolean', 'default' => '0'],
                'low_stock_notify' => ['type' => 'boolean', 'default' => '1'],
                'interview_hours' => ['type' => 'string', 'default' => '12:00-16:00'],
                'observation_hours' => ['type' => 'string', 'default' => '07:30-17:30'],
                'stock_loss_alert_percent' => ['type' => 'number', 'default' => '10'],
                'default_member_rank' => ['type' => 'string', 'default' => 'Distributor'],
            ],
            'heavy-equipment' => [
                'default_rental_days' => ['type' => 'number', 'default' => '7'],
                'maintenance_interval_hours' => ['type' => 'number', 'default' => '250'],
                'require_operator_license' => ['type' => 'boolean', 'default' => '1'],
            ],
            'real-estate' => [
                'default_lease_months' => ['type' => 'number', 'default' => '12'],
                'occupancy_alert_percent' => ['type' => 'number', 'default' => '80'],
                'late_rent_grace_days' => ['type' => 'number', 'default' => '5'],
            ],
            'rice-mills' => [
                'standard_bag_kg' => ['type' => 'number', 'default' => '50'],
                'moisture_max_percent' => ['type' => 'number', 'default' => '14'],
                'mill_shift_hours' => ['type' => 'number', 'default' => '8'],
            ],
            'lali-tv' => [
                'default_broadcast_tz' => ['type' => 'string', 'default' => 'Africa/Kinshasa'],
                'max_upload_mb' => ['type' => 'number', 'default' => '512'],
                'content_review_required' => ['type' => 'boolean', 'default' => '1'],
            ],
            'machinery-fleet' => [
                'fleet_alert_days' => ['type' => 'number', 'default' => '14'],
                'default_service_interval_km' => ['type' => 'number', 'default' => '5000'],
                'require_driver_license' => ['type' => 'boolean', 'default' => '1'],
            ],
        ];
    }

    public static function meta(string $group, string $key): ?array
    {
        return self::all()[$group][$key] ?? null;
    }

    /**
     * Ensure every catalog key exists in the settings table.
     */
    public static function ensure(): void
    {
        foreach (self::all() as $group => $keys) {
            foreach ($keys as $key => $meta) {
                self::ensureKey($group, $key, $meta);
            }
        }
    }

    /**
     * Read a setting from the database. Missing catalog keys are created from the catalog default.
     */
    public static function get(string $group, string $key, mixed $fallback = null): mixed
    {
        $meta = self::meta($group, $key);
        $default = $meta ? self::cast($meta['default'], $meta['type']) : $fallback;

        try {
            if ($meta) {
                self::ensureKey($group, $key, $meta);
            }

            $row = Setting::query()->where('group', $group)->where('key', $key)->first();
            if (! $row) {
                return $default;
            }

            return self::cast($row->value, $row->type ?: ($meta['type'] ?? 'string'), $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function string(string $group, string $key, string $fallback = ''): string
    {
        return (string) self::get($group, $key, $fallback);
    }

    public static function number(string $group, string $key, float|int $fallback = 0): float
    {
        return (float) self::get($group, $key, $fallback);
    }

    public static function bool(string $group, string $key, bool $fallback = false): bool
    {
        return (bool) self::get($group, $key, $fallback);
    }

    public static function defaultLocale(): string
    {
        $locale = strtolower(self::string('general', 'default_locale', 'fr'));

        return in_array($locale, ['fr', 'en'], true) ? $locale : 'fr';
    }

    /**
     * @param  array{type: string, default: string}  $meta
     */
    private static function ensureKey(string $group, string $key, array $meta): void
    {
        Setting::query()->firstOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $meta['default'], 'type' => $meta['type']]
        );
    }

    private static function cast(mixed $value, string $type, mixed $fallback = null): mixed
    {
        return match ($type) {
            'number' => is_numeric($value) ? (float) $value : $fallback,
            'boolean' => in_array((string) $value, ['1', 'true', 'yes', 'on'], true),
            'json' => is_string($value)
                ? (json_decode($value, true) ?? $fallback)
                : ($value ?? $fallback),
            default => $value ?? $fallback,
        };
    }
}
