<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'slug', 'name', 'name_fr', 'description', 'description_fr',
    'icon', 'version', 'status', 'sort_order', 'is_core',
])]
class SystemModule extends Model
{
    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): HasOne
    {
        return $this->hasOne(Company::class, 'system_module_id');
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_module')
            ->withPivot(['is_enabled', 'settings'])
            ->withTimestamps();
    }
}
