<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['base_code', 'quote_code', 'mid_rate', 'fetched_at', 'source'])]
class ExchangeRate extends Model
{
    protected function casts(): array
    {
        return [
            'mid_rate' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
