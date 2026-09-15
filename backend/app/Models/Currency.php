<?php

namespace App\Models;

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
        return static::query()->where('is_default', true)->value('code')
            ?: (string) Setting::value('finance', 'reporting_currency', 'USD');
    }
}
