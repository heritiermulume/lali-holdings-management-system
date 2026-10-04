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
            ],
            'finance' => [
                'decimal_places' => ['type' => 'number', 'default' => '2'],
                'fiscal_year_start_month' => ['type' => 'number', 'default' => '1'],
                'tax_inclusive_prices' => ['type' => 'boolean', 'default' => '0'],
                'fx_spread_percent' => ['type' => 'number', 'default' => '1.5'],
                'reporting_currency' => ['type' => 'string', 'default' => 'USD'],
                'multi_currency' => ['type' => 'boolean', 'default' => '1'],
            ],
            'security' => [
                'password_min_length' => ['type' => 'number', 'default' => '10'],
                'password_require_mixed' => ['type' => 'boolean', 'default' => '1'],
                'session_lifetime_minutes' => ['type' => 'number', 'default' => '120'],
                'force_password_reset_days' => ['type' => 'number', 'default' => '90'],
                'audit_retention_days' => ['type' => 'number', 'default' => '365'],
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
        ];
    }

    public static function ensure(): void
    {
        foreach (self::all() as $group => $keys) {
            foreach ($keys as $key => $meta) {
                Setting::query()->firstOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $meta['default'], 'type' => $meta['type']]
                );
            }
        }
    }

    public static function defaultLocale(): string
    {
        self::ensure();
        $locale = strtolower((string) Setting::getValue('general', 'default_locale', 'fr'));

        return in_array($locale, ['fr', 'en'], true) ? $locale : 'fr';
    }
}
