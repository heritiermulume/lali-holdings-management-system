<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'country_id', 'city_id', 'system_module_id', 'name', 'legal_name', 'code', 'email', 'phone',
    'address', 'tax_id', 'currency_code', 'logo_path', 'description', 'is_active',
])]
class Company extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(SystemModule::class, 'system_module_id');
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(SystemModule::class, 'company_module')
            ->withPivot(['is_enabled', 'settings'])
            ->withTimestamps();
    }

    public function financialSnapshots(): HasMany
    {
        return $this->hasMany(FinancialSnapshot::class);
    }
}
