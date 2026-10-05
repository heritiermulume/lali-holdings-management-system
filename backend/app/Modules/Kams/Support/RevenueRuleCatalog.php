<?php

namespace App\Modules\Kams\Support;

class RevenueRuleCatalog
{
    public const MANAGER_SALE = 'manager_sale';
    public const MANAGER_OFFICE = 'manager_office';
    public const MANAGER_DESCENDANT_OFFICE = 'manager_descendant_office';
    public const DIVISIONNAIRE_OFFICE = 'divisionnaire_office';
    public const SECRETARY_OFFICE = 'secretary_office';
    public const ASSISTANT_MANAGER = 'assistant_manager';

    /**
     * Seed-only defaults. Runtime code must read percents/divisors from kams_revenue_rules.
     *
     * @return list<array{
     *   code: string,
     *   role_slug: string,
     *   label: string,
     *   percent: float,
     *   divisor: float|null,
     *   basis: string,
     *   scope: string,
     *   paid_from: string|null,
     *   sort_order: int
     * }>
     */
    public static function defaults(): array
    {
        return [
            [
                'code' => self::MANAGER_SALE,
                'role_slug' => 'manager',
                'label' => 'Manager sale share',
                'percent' => 20,
                'divisor' => null,
                'basis' => 'product_price',
                'scope' => 'sale',
                'paid_from' => null,
                'sort_order' => 10,
            ],
            [
                'code' => self::ASSISTANT_MANAGER,
                'role_slug' => 'assistant_manager',
                'label' => 'Assistant manager team share',
                'percent' => 20,
                'divisor' => 4,
                'basis' => 'team_sales',
                'scope' => 'sale',
                'paid_from' => self::MANAGER_SALE,
                'sort_order' => 20,
            ],
            [
                'code' => self::MANAGER_OFFICE,
                'role_slug' => 'manager',
                'label' => 'Manager office opened',
                'percent' => 3,
                'divisor' => null,
                'basis' => 'office_sales',
                'scope' => 'direct_office',
                'paid_from' => null,
                'sort_order' => 30,
            ],
            [
                'code' => self::MANAGER_DESCENDANT_OFFICE,
                'role_slug' => 'manager',
                'label' => 'Manager descendant offices',
                'percent' => 3,
                'divisor' => null,
                'basis' => 'office_sales',
                'scope' => 'descendant_offices',
                'paid_from' => null,
                'sort_order' => 40,
            ],
            [
                'code' => self::DIVISIONNAIRE_OFFICE,
                'role_slug' => 'divisionnaire',
                'label' => 'Divisionnaire office hierarchy',
                'percent' => 4,
                'divisor' => null,
                'basis' => 'office_sales',
                'scope' => 'descendant_offices',
                'paid_from' => null,
                'sort_order' => 50,
            ],
            [
                'code' => self::SECRETARY_OFFICE,
                'role_slug' => 'secretary',
                'label' => 'Secretary direct office',
                'percent' => 1,
                'divisor' => null,
                'basis' => 'office_sales',
                'scope' => 'manager_direct_office',
                'paid_from' => null,
                'sort_order' => 60,
            ],
        ];
    }

    public static function savingsKind(string $ruleCode): string
    {
        return match ($ruleCode) {
            self::ASSISTANT_MANAGER => 'assistant_manager',
            self::SECRETARY_OFFICE => 'secretary',
            self::DIVISIONNAIRE_OFFICE => 'divisionnaire',
            default => 'manager',
        };
    }

    public static function percentOf(float $base, float $percent): float
    {
        return round($base * ($percent / 100), 2);
    }

    public static function assistantShare(float $teamSales, float $divisor, float $managerPercent): float
    {
        if ($divisor <= 0) {
            return 0.0;
        }

        return round(($teamSales / $divisor) * ($managerPercent / 100), 2);
    }

    public static function formula(string $code, float $base, float $percent, ?float $divisor = null): string
    {
        if ($code === self::ASSISTANT_MANAGER) {
            $div = $divisor && $divisor > 0 ? $divisor : 0;

            return sprintf('(%s / %s) × %s%%', self::number($base), self::number($div), self::number($percent));
        }

        return sprintf('%s × %s%%', self::number($base), self::number($percent));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
