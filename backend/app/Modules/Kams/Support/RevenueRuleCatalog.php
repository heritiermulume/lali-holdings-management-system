<?php

namespace App\Modules\Kams\Support;

class RevenueRuleCatalog
{
    public const MANAGER_SALE = 'manager_sale';
    public const MANAGER_OFFICE = 'manager_office';
    public const MANAGER_CERTIFIED_OFFICE = 'manager_certified_office';
    public const MANAGER_DESCENDANT_OFFICE = 'manager_descendant_office';
    public const DIVISIONNAIRE_OFFICE = 'divisionnaire_office';
    public const SECRETARY_OFFICE = 'secretary_office';
    public const ASSISTANT_MANAGER = 'assistant_manager';
    public const ASSISTANT_MANAGER_CERTIFIED = 'assistant_manager_certified';

    /** Fixed eighth used for certified AM surplus on sub-AM team production. */
    public const CERTIFIED_ASSISTANT_DIVISOR = 8.0;

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
            // Kept for share accounting (rule_code). Percent comes from each product's manager_share_percent.
            [
                'code' => self::MANAGER_SALE,
                'role_slug' => 'manager',
                'label' => 'Manager sale share',
                'percent' => 0,
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
            self::ASSISTANT_MANAGER, self::ASSISTANT_MANAGER_CERTIFIED => 'assistant_manager',
            self::SECRETARY_OFFICE => 'secretary',
            self::DIVISIONNAIRE_OFFICE => 'divisionnaire',
            self::MANAGER_CERTIFIED_OFFICE => 'percentage',
            default => 'manager',
        };
    }

    /**
     * Office-opening % held as payable commissions (not auto-credited on day close).
     * Manager / divisionnaire who opened (or oversees) the office.
     *
     * @return list<string>
     */
    public static function heldOfficeCommissionCodes(): array
    {
        return [
            self::MANAGER_OFFICE,
            self::MANAGER_DESCENDANT_OFFICE,
            self::MANAGER_CERTIFIED_OFFICE,
            self::DIVISIONNAIRE_OFFICE,
        ];
    }

    public static function isHeldOfficeCommission(string $ruleCode): bool
    {
        return in_array($ruleCode, self::heldOfficeCommissionCodes(), true);
    }

    /**
     * Roles that split own-office pay (Compte Salaire) from hierarchy % (Compte Overed).
     * VP / partner / divisionnaire use the same office-production package as managers.
     */
    public static function usesSplitCompensationAccounts(?string $roleSlug): bool
    {
        return in_array($roleSlug, [
            'manager',
            'vice_president',
            'partner',
            'secretary',
            'divisionnaire',
        ], true);
    }

    /**
     * Resolve the savings wallet for a compensation credit.
     * Own office → salary; other opened / descendant offices → percentage (Overed).
     */
    public static function compensationAccountKind(
        string $ruleCode,
        ?string $roleSlug,
        ?int $shareOfficeId,
        ?int $memberOfficeId,
    ): string {
        // Certified surplus always credits Compte Overed (percentage), never salary.
        if ($ruleCode === self::MANAGER_CERTIFIED_OFFICE) {
            return 'percentage';
        }

        if (
            in_array($ruleCode, [self::ASSISTANT_MANAGER, self::ASSISTANT_MANAGER_CERTIFIED], true)
            || ! self::usesSplitCompensationAccounts($roleSlug)
        ) {
            return self::savingsKind($ruleCode);
        }

        if ($shareOfficeId !== null && $memberOfficeId !== null && (int) $shareOfficeId === (int) $memberOfficeId) {
            return 'salary';
        }

        if ($shareOfficeId === null) {
            return 'salary';
        }

        return 'percentage';
    }

    public static function percentOf(float $base, float $percent): float
    {
        return round($base * ($percent / 100), 2);
    }

    /**
     * Office turnover remaining after the local office manager's sale share.
     */
    public static function officeSalesAfterManagerShare(float $officeSales, float $managerPercent): float
    {
        return max(0.0, round($officeSales - self::percentOf($officeSales, $managerPercent), 2));
    }

    /**
     * Base assistant manager share on direct team production (settings divisor × product manager %).
     */
    public static function assistantShare(float $teamSales, float $divisor, float $managerPercent): float
    {
        if ($divisor <= 0) {
            return 0.0;
        }

        return round(($teamSales / $divisor) * ($managerPercent / 100), 2);
    }

    /**
     * Certified AM surplus: 1/8 of a sub-assistant-manager's team production.
     */
    public static function certifiedAssistantShare(float $subAssistantTeamSales, float $divisor = self::CERTIFIED_ASSISTANT_DIVISOR): float
    {
        if ($divisor <= 0) {
            return 0.0;
        }

        return round($subAssistantTeamSales / $divisor, 2);
    }

    public static function formula(string $code, float $base, float $percent, ?float $divisor = null, ?float $grossBase = null, ?float $managerPercent = null): string
    {
        if ($code === self::ASSISTANT_MANAGER) {
            $div = $divisor && $divisor > 0 ? $divisor : 0;

            return sprintf('(%s / %s) × %s%%', self::number($base), self::number($div), self::number($percent));
        }

        if ($code === self::ASSISTANT_MANAGER_CERTIFIED) {
            $div = $divisor && $divisor > 0 ? $divisor : self::CERTIFIED_ASSISTANT_DIVISOR;

            return sprintf('%s ÷ %s · certified AM', self::number($base), self::number($div));
        }

        if (
            in_array($code, [
                self::MANAGER_OFFICE,
                self::MANAGER_CERTIFIED_OFFICE,
                self::MANAGER_DESCENDANT_OFFICE,
                self::DIVISIONNAIRE_OFFICE,
                self::SECRETARY_OFFICE,
            ], true)
            && $grossBase !== null
            && $managerPercent !== null
        ) {
            return sprintf(
                '(%s − %s%% manager) × %s%%',
                self::number($grossBase),
                self::number($managerPercent),
                self::number($percent)
            );
        }

        return sprintf('%s × %s%%', self::number($base), self::number($percent));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
