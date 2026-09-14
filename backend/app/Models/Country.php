<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'name_fr', 'iso_code', 'phone_code', 'currency_code', 'currency_symbol', 'is_active'])]
class Country extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
}
