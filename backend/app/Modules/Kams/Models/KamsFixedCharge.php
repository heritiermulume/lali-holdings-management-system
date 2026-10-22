<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'office_id',
    'name',
    'amount',
    'period',
    'starts_on',
    'days',
    'ends_on',
    'notes',
    'is_active',
    'created_by',
])]
class KamsFixedCharge extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'days' => 'integer',
            'is_active' => 'boolean',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
