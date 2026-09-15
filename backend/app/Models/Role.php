<?php

namespace App\Models;

use App\Core\Support\RoleCatalog;
use App\Models\Office;
use App\Models\Permission;
use App\Models\User;
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
        return $this->belongsToMany(User::class);
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class)->withTimestamps();
    }

    public function hasFullAccess(): bool
    {
        return in_array($this->slug, ['super_administrator', 'president'], true);
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
