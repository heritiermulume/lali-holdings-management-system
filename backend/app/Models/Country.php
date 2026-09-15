<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'name_fr', 'iso_code', 'phone_code', 'currency_code', 'currency_symbol', 'tax_name', 'tax_rate', 'latitude', 'longitude', 'is_active'])]
class Country extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tax_rate' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class);
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }
}
