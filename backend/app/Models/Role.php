<?php

namespace App\Models;

use App\Core\Support\RoleCatalog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'slug', 'module_slug', 'description', 'level', 'is_system'])]
class Role extends Model
{
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'level' => 'integer',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('company_id');
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class)->withTimestamps();
    }

    public function hasFullAccess(): bool
    {
        return RoleCatalog::isFullAccess((string) $this->slug);
    }

    public function isSystemWide(): bool
    {
        return ! $this->module_slug || $this->module_slug === 'system';
    }

    public function isOfficeScoped(): bool
    {
        return RoleCatalog::isOfficeScoped((string) $this->slug);
    }
}
