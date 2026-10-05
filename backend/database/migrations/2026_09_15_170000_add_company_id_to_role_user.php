<?php

use App\Core\Support\RoleCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            // The unique (role_id, user_id) is the only index covering role_id,
            // so InnoDB uses it for the foreign key. Add a dedicated index first.
            $table->index('role_id');
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->dropUnique(['role_id', 'user_id']);
        });

        Schema::table('role_user', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        $platform = RoleCatalog::fullAccessSlugs();

        $rows = DB::table('role_user')->get();
        foreach ($rows as $row) {
            $slug = Role::query()->whereKey($row->role_id)->value('slug');
            if (in_array($slug, $platform, true)) {
                DB::table('role_user')->where('id', $row->id)->update(['company_id' => null]);

                continue;
            }

            $companyId = User::query()->whereKey($row->user_id)->value('primary_company_id');
            DB::table('role_user')->where('id', $row->id)->update(['company_id' => $companyId]);
        }
    }

    public function down(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->unique(['role_id', 'user_id']);
            $table->dropIndex(['role_id']);
        });
    }
};
