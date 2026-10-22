<?php

namespace Tests\Unit;

use App\Modules\Kams\Support\RevenueRuleCatalog;
use PHPUnit\Framework\TestCase;

class RevenueRuleCatalogTest extends TestCase
{
    public function test_manager_receives_twenty_percent_of_product_price(): void
    {
        $this->assertSame(20.0, RevenueRuleCatalog::percentOf(100, 20));
        $this->assertSame(40.0, RevenueRuleCatalog::percentOf(200, 20));
    }

    public function test_assistant_formula_uses_team_sales_divisor_and_manager_percent(): void
    {
        $this->assertSame(20.0, RevenueRuleCatalog::assistantShare(400, 4, 20));
        $this->assertSame(5.0, RevenueRuleCatalog::assistantShare(100, 4, 20));
    }

    public function test_certified_assistant_share_is_one_eighth_of_sub_am_team_production(): void
    {
        $this->assertSame(50.0, RevenueRuleCatalog::certifiedAssistantShare(400));
        $this->assertSame(12.5, RevenueRuleCatalog::certifiedAssistantShare(100));
        $this->assertSame(1000.0, RevenueRuleCatalog::certifiedAssistantShare(8000));
    }

    public function test_divisionnaire_share_uses_percent_and_divisor(): void
    {
        $this->assertSame(4.0, RevenueRuleCatalog::scaledShare(100, 4, null));
        $this->assertSame(20.0, RevenueRuleCatalog::scaledShare(400, 20, 4));
        $this->assertSame(
            '(4500 / 4) × 4% · certified',
            RevenueRuleCatalog::formula(RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE, 4500, 4, 4)
        );
    }

    public function test_office_percentages(): void
    {
        $this->assertSame(3.0, RevenueRuleCatalog::percentOf(100, 3));
        $this->assertSame(4.0, RevenueRuleCatalog::percentOf(100, 4));
        $this->assertSame(1.0, RevenueRuleCatalog::percentOf(100, 1));
    }

    public function test_manager_office_uses_sales_after_office_manager_share(): void
    {
        $this->assertSame(80.0, RevenueRuleCatalog::officeSalesAfterManagerShare(100, 20));
        $this->assertSame(2.4, RevenueRuleCatalog::percentOf(
            RevenueRuleCatalog::officeSalesAfterManagerShare(100, 20),
            3
        ));
    }

    public function test_assistant_formula_string(): void
    {
        $this->assertSame('(400 / 4) × 20%', RevenueRuleCatalog::formula(RevenueRuleCatalog::ASSISTANT_MANAGER, 400, 20, 4));
        $this->assertSame(
            '400 ÷ 8 · certified AM',
            RevenueRuleCatalog::formula(RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED, 400, 0, 8)
        );
        $this->assertSame('100 × 20%', RevenueRuleCatalog::formula(RevenueRuleCatalog::MANAGER_SALE, 100, 20));
        $this->assertSame(
            '(100 − 20 part manager avant AM) × 3%',
            RevenueRuleCatalog::formula(RevenueRuleCatalog::MANAGER_OFFICE, 80, 3, null, 100, 20, 20)
        );
        $this->assertSame(
            '(100 − 20 part divisionnaire avant AM) × 4%',
            RevenueRuleCatalog::formula(RevenueRuleCatalog::DIVISIONNAIRE_OFFICE, 80, 4, null, 100, null, 20, 'divisionnaire')
        );
        $this->assertSame(
            '((100 − 20 part manager avant AM) / 4) × 4%',
            RevenueRuleCatalog::formula(RevenueRuleCatalog::DIVISIONNAIRE_OFFICE, 80, 4, 4, 100, null, 20, 'manager')
        );
        $this->assertSame(80.0, RevenueRuleCatalog::officeSalesAfterLeaderShare(100, 20));
        $this->assertSame(
            '(100 − 20 part manager avant AM) × 1%',
            RevenueRuleCatalog::formula(RevenueRuleCatalog::SECRETARY_OFFICE, 80, 1, null, 100, 20, 20)
        );
    }

    public function test_assistant_share_requires_positive_divisor(): void
    {
        $this->assertSame(0.0, RevenueRuleCatalog::assistantShare(400, 0, 20));
        $this->assertSame(0.0, RevenueRuleCatalog::assistantShare(400, -2, 20));
        $this->assertSame(0.0, RevenueRuleCatalog::certifiedAssistantShare(400, 0));
    }

    public function test_assistant_manager_default_divisor_is_four(): void
    {
        $am = collect(RevenueRuleCatalog::defaults())->firstWhere('code', RevenueRuleCatalog::ASSISTANT_MANAGER);
        $this->assertNotNull($am);
        $this->assertSame(4.0, (float) $am['divisor']);
        $this->assertSame(20.0, (float) $am['percent']);
    }

    public function test_compensation_account_kind_splits_own_office_from_others(): void
    {
        $this->assertTrue(RevenueRuleCatalog::usesSplitCompensationAccounts('manager'));
        $this->assertTrue(RevenueRuleCatalog::usesSplitCompensationAccounts('vice_president'));
        $this->assertTrue(RevenueRuleCatalog::usesSplitCompensationAccounts('partner'));
        $this->assertTrue(RevenueRuleCatalog::usesSplitCompensationAccounts('secretary'));
        $this->assertTrue(RevenueRuleCatalog::usesSplitCompensationAccounts('divisionnaire'));
        $this->assertFalse(RevenueRuleCatalog::usesSplitCompensationAccounts('assistant_manager'));

        $this->assertSame(
            'salary',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::MANAGER_SALE, 'manager', 10, 10)
        );
        $this->assertSame(
            'salary',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::MANAGER_SALE, 'vice_president', 10, 10)
        );
        $this->assertSame(
            'salary',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::MANAGER_SALE, 'divisionnaire', 10, 10)
        );
        $this->assertSame(
            'percentage',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::MANAGER_OFFICE, 'vice_president', 22, 10)
        );
        $this->assertSame(
            'percentage',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::MANAGER_OFFICE, 'manager', 22, 10)
        );
        $this->assertSame(
            'percentage',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::DIVISIONNAIRE_OFFICE, 'divisionnaire', 5, 1)
        );
        $this->assertSame(
            'guy',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::ASSISTANT_MANAGER, 'assistant_manager', 10, 10)
        );
        $this->assertSame(
            'guy',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::ASSISTANT_MANAGER_CERTIFIED, 'assistant_manager', 10, 10)
        );
        $this->assertSame(
            'percentage',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE, 'manager', 10, 10)
        );
        $this->assertSame(
            'percentage',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE, 'partner', 10, 10)
        );
        $this->assertSame(
            'percentage',
            RevenueRuleCatalog::compensationAccountKind(RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE, 'divisionnaire', 10, 10)
        );
        $this->assertTrue(RevenueRuleCatalog::isHeldOfficeCommission(RevenueRuleCatalog::MANAGER_SALE));
        $this->assertFalse(RevenueRuleCatalog::isHeldOfficeCommission(RevenueRuleCatalog::ASSISTANT_MANAGER));
        $this->assertTrue(RevenueRuleCatalog::isHeldOfficeCommission(RevenueRuleCatalog::SECRETARY_OFFICE));
        $this->assertTrue(RevenueRuleCatalog::isHeldOfficeCommission(RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE));
        $this->assertTrue(RevenueRuleCatalog::isHeldOfficeCommission(RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE));
        $this->assertTrue(RevenueRuleCatalog::isHeldOfficeCommission(RevenueRuleCatalog::DIVISIONNAIRE_OFFICE));
        $this->assertTrue(RevenueRuleCatalog::creditsOnDayClose(RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE, 4, 4));
        $this->assertTrue(RevenueRuleCatalog::creditsOnDayClose(RevenueRuleCatalog::MANAGER_CERTIFIED_OFFICE, 2, 2));
        $this->assertTrue(RevenueRuleCatalog::creditsOnDayClose(RevenueRuleCatalog::MANAGER_SALE, 2, 2));
        $this->assertFalse(RevenueRuleCatalog::creditsOnDayClose(RevenueRuleCatalog::DIVISIONNAIRE_CERTIFIED_OFFICE, 8, 4));
        $this->assertFalse(RevenueRuleCatalog::creditsOnDayClose(RevenueRuleCatalog::MANAGER_DESCENDANT_OFFICE, 8, 2));
        $this->assertTrue(RevenueRuleCatalog::creditsOnDayClose(RevenueRuleCatalog::ASSISTANT_MANAGER, 8, 2));
        $this->assertSame(4500.0, RevenueRuleCatalog::officeSalesAfterManagerShare(6000, 25));
    }
};
