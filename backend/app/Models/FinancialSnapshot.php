<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'source_module', 'period', 'revenue', 'expenses', 'profit',
    'cash', 'assets', 'liabilities', 'receivables', 'payables',
])]
class FinancialSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'revenue' => 'float',
            'expenses' => 'float',
            'profit' => 'float',
            'cash' => 'float',
            'assets' => 'float',
            'liabilities' => 'float',
            'receivables' => 'float',
            'payables' => 'float',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
