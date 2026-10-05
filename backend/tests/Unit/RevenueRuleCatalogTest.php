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

    public function test_office_percentages(): void
    {
        $this->assertSame(3.0, RevenueRuleCatalog::percentOf(100, 3));
        $this->assertSame(4.0, RevenueRuleCatalog::percentOf(100, 4));
        $this->assertSame(1.0, RevenueRuleCatalog::percentOf(100, 1));
    }

    public function test_assistant_formula_string(): void
    {
        $this->assertSame('(400 / 4) × 20%', RevenueRuleCatalog::formula(RevenueRuleCatalog::ASSISTANT_MANAGER, 400, 20, 4));
        $this->assertSame('100 × 20%', RevenueRuleCatalog::formula(RevenueRuleCatalog::MANAGER_SALE, 100, 20));
    }

    public function test_assistant_share_requires_positive_divisor(): void
    {
        $this->assertSame(0.0, RevenueRuleCatalog::assistantShare(400, 0, 20));
        $this->assertSame(0.0, RevenueRuleCatalog::assistantShare(400, -2, 20));
    }
}
