<?php

use App\Core\Support\RoleCatalog;
use App\Models\Office;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('module_slug', 64)->nullable()->after('slug')->index();
        });

        foreach (RoleCatalog::all() as $role) {
            Role::query()->where('slug', $role['slug'])->update([
                'module_slug' => $role['module_slug'],
            ]);
        }

        $kamsOfficeIds = Office::query()
            ->whereHas('company.module', fn ($module) => $module->where('slug', 'kams'))
            ->pluck('id');

        if ($kamsOfficeIds->isEmpty()) {
            return;
        }

        Role::query()
            ->where('module_slug', 'kams')
            ->get()
            ->each(function (Role $role) use ($kamsOfficeIds) {
                $role->offices()->syncWithoutDetaching($kamsOfficeIds);
            });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('module_slug');
        });
    }
};
