<?php

namespace App\Modules\Kams\Models;

use App\Models\Company;
use App\Models\Office;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['company_id', 'office_id', 'kind', 'name', 'email', 'phone', 'city', 'segment', 'status', 'notes'])]
class KamsClient extends Model
{
    use SoftDeletes;

    public function isSupplier(): bool
    {
        return $this->kind === 'supplier';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(KamsDepot::class, 'kams_client_warehouse', 'client_id', 'warehouse_id')
            ->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(KamsOrder::class, 'client_id');
    }
}
