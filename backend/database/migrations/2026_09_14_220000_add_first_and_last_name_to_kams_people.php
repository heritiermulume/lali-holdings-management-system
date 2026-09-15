<?php

use App\Modules\Kams\Support\PersonName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('candidate_id');
            $table->string('last_name')->nullable()->after('first_name');
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('member_code');
            $table->string('last_name')->nullable()->after('first_name');
        });

        foreach (['kams_candidates', 'kams_members'] as $table) {
            DB::table($table)->orderBy('id')->select('id', 'full_name')->chunkById(100, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $names = PersonName::fromFullName($row->full_name);
                    DB::table($table)->where('id', $row->id)->update([
                        'first_name' => $names['first_name'] ?: null,
                        'last_name' => $names['last_name'] ?: null,
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
