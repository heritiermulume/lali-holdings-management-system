<?php

namespace App\Models;

use App\Support\SettingsCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'name', 'name_fr', 'symbol', 'is_default', 'is_active', 'sort_order'])]
class Currency extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function defaultCode(): string
    {
        try {
            $code = static::query()->where('is_default', true)->value('code')
                ?: static::query()->where('is_active', true)->orderBy('sort_order')->value('code')
                ?: SettingsCatalog::string('finance', 'reporting_currency');

            return $code ? strtoupper((string) $code) : '';
        } catch (\Throwable) {
            $code = SettingsCatalog::string('finance', 'reporting_currency');

            return $code ? strtoupper((string) $code) : '';
        }
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', strtoupper($code))->first();
    }
}
