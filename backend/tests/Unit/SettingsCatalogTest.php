<?php

namespace Tests\Unit;

use App\Support\SettingsCatalog;
use PHPUnit\Framework\TestCase;

class SettingsCatalogTest extends TestCase
{
    public function test_catalog_contains_core_groups(): void
    {
        $all = SettingsCatalog::all();

        $this->assertArrayHasKey('general', $all);
        $this->assertArrayHasKey('finance', $all);
        $this->assertArrayHasKey('security', $all);
        $this->assertArrayHasKey('kams', $all);
        $this->assertSame('1.5', $all['finance']['fx_spread_percent']['default']);
        $this->assertSame('07:30-17:30', $all['kams']['observation_hours']['default']);
        $this->assertArrayHasKey('mail_host', $all['general']);
        $this->assertArrayHasKey('mail_from_address', $all['general']);
        $this->assertSame('587', $all['general']['mail_port']['default']);
    }

    public function test_meta_returns_typed_definition(): void
    {
        $meta = SettingsCatalog::meta('finance', 'decimal_places');

        $this->assertIsArray($meta);
        $this->assertSame('number', $meta['type']);
        $this->assertSame('2', $meta['default']);
    }

    public function test_cast_helpers_work_without_database(): void
    {
        // When DB is unavailable, get() falls back to catalog defaults.
        $this->assertSame(1.5, SettingsCatalog::number('finance', 'fx_spread_percent'));
        $this->assertTrue(SettingsCatalog::bool('security', 'password_require_mixed'));
        $this->assertSame('LALI Holdings', SettingsCatalog::string('general', 'group_name'));
    }
}
