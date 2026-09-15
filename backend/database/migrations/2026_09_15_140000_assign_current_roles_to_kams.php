<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()
            ->whereIn('slug', ['super_administrator', 'president'])
            ->update(['module_slug' => 'kams']);
    }

    public function down(): void
    {
        Role::query()
            ->whereIn('slug', ['super_administrator', 'president'])
            ->update(['module_slug' => 'system']);
    }
};
