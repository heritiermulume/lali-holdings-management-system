<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_members', function (Blueprint $table) {
            $table->foreignId('coach_member_id')->nullable()->after('trainer_user_id')->constrained('kams_members')->nullOnDelete();
            $table->string('coach_name')->nullable()->after('coach_member_id');
        });

        if (! Schema::hasTable('kams_observation_days')) {
            return;
        }

        $rows = DB::table('kams_observation_days')
            ->join('kams_candidates', 'kams_candidates.id', '=', 'kams_observation_days.candidate_id')
            ->where('kams_observation_days.decision', 'approved')
            ->whereNotNull('kams_candidates.member_id')
            ->whereNotNull('kams_observation_days.supervisor_member_id')
            ->orderByDesc('kams_observation_days.id')
            ->get([
                'kams_candidates.member_id',
                'kams_observation_days.supervisor_member_id',
                'kams_observation_days.supervisor_name',
            ]);

        $seen = [];
        foreach ($rows as $row) {
            $memberId = (int) $row->member_id;
            if (isset($seen[$memberId])) {
                continue;
            }
            $seen[$memberId] = true;
            DB::table('kams_members')
                ->where('id', $memberId)
                ->whereNull('coach_member_id')
                ->update([
                    'coach_member_id' => $row->supervisor_member_id,
                    'coach_name' => $row->supervisor_name,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coach_member_id');
            $table->dropColumn('coach_name');
        });
    }
};
