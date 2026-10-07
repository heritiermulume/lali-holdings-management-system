<?php

namespace App\Modules\Kams\Support;

use App\Support\SettingsCatalog;
use Illuminate\Support\Str;

class KamsDocumentRefs
{
    public static function order(): string
    {
        return self::make('order_prefix', 'KA');
    }

    public static function invoice(): string
    {
        return self::make('invoice_prefix', 'KAF');
    }

    private static function make(string $key, string $fallback): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', SettingsCatalog::string('kams', $key)));

        return ($prefix !== '' ? $prefix : $fallback).'-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
    }
}
