<?php

use App\Core\Support\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });

        $rows = DB::table('role_user')->whereNotNull('company_id')->get();

        foreach ($rows as $row) {
            $slug = DB::table('roles')->where('id', $row->role_id)->value('slug');
            if (! $slug || ! RoleCatalog::isOfficeScoped((string) $slug)) {
                continue;
            }

            $officeIds = DB::table('user_scopes')
                ->join('offices', 'offices.id', '=', 'user_scopes.scope_id')
                ->where('user_scopes.user_id', $row->user_id)
                ->where('user_scopes.scope_type', 'office')
                ->where('offices.company_id', $row->company_id)
                ->pluck('offices.id')
                ->unique()
                ->values();

            if ($officeIds->isEmpty()) {
                continue;
            }

            $first = (int) $officeIds->shift();
            DB::table('role_user')->where('id', $row->id)->update(['office_id' => $first]);

            foreach ($officeIds as $officeId) {
                DB::table('role_user')->insert([
                    'role_id' => $row->role_id,
                    'user_id' => $row->user_id,
                    'company_id' => $row->company_id,
                    'office_id' => $officeId,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_id');
        });
    }
};
