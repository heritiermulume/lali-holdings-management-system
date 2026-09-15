<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['commune_id', 'name', 'name_fr', 'is_active'])]
class Neighborhood extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    public function offices(): HasMany
    {
        return $this->hasMany(Office::class);
    }
}
