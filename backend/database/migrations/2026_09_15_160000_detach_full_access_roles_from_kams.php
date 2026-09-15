<?php

use App\Core\Support\RoleCatalog;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()
            ->whereIn('slug', RoleCatalog::fullAccessSlugs())
            ->update(['module_slug' => null]);
    }

    public function down(): void
    {
        Role::query()
            ->whereIn('slug', RoleCatalog::fullAccessSlugs())
            ->update(['module_slug' => 'kams']);
    }
};
