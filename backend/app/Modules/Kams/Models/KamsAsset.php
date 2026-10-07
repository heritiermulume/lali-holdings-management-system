<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id',
    'office_id',
    'name',
    'category',
    'quantity',
    'unit_value',
    'acquired_on',
    'status',
    'notes',
    'image_path',
    'created_by',
])]
class KamsAsset extends Model
{
    use SoftDeletes;

    protected $appends = ['total_value', 'image_url'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_value' => 'float',
            'acquired_on' => 'date',
        ];
    }

    protected function totalValue(): Attribute
    {
        return Attribute::get(fn (): float => round(((int) $this->quantity) * (float) $this->unit_value, 2));
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->image_path ? '/storage/'.$this->image_path : null);
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
