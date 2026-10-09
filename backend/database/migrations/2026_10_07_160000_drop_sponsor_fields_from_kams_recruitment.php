<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve useful parrain data on the member as encadreur before dropping columns.
        if (Schema::hasColumn('kams_candidates', 'sponsor_member_id') && Schema::hasColumn('kams_members', 'coach_member_id')) {
            $rows = DB::table('kams_candidates')
                ->whereNotNull('sponsor_member_id')
                ->whereNotNull('member_id')
                ->get(['id', 'member_id', 'sponsor_member_id', 'sponsor_name']);

            foreach ($rows as $row) {
                $member = DB::table('kams_members')->where('id', $row->member_id)->first();
                if (! $member || $member->coach_member_id) {
                    continue;
                }

                $sponsor = DB::table('kams_members')->where('id', $row->sponsor_member_id)->first();
                if (! $sponsor) {
                    continue;
                }

                DB::table('kams_members')->where('id', $member->id)->update([
                    'coach_member_id' => $sponsor->id,
                    'coach_name' => $sponsor->full_name,
                    'trainer_user_id' => $sponsor->user_id,
                    'trainer_name' => $sponsor->full_name,
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('kams_observation_days') && Schema::hasColumn('kams_observation_days', 'sponsor_member_id')) {
            Schema::table('kams_observation_days', function (Blueprint $table) {
                $table->dropConstrainedForeignId('sponsor_member_id');
            });
        }

        if (Schema::hasTable('kams_candidates')) {
            Schema::table('kams_candidates', function (Blueprint $table) {
                if (Schema::hasColumn('kams_candidates', 'sponsor_member_id')) {
                    $table->dropConstrainedForeignId('sponsor_member_id');
                }
                if (Schema::hasColumn('kams_candidates', 'sponsor_name')) {
                    $table->dropColumn('sponsor_name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('kams_candidates')) {
            Schema::table('kams_candidates', function (Blueprint $table) {
                if (! Schema::hasColumn('kams_candidates', 'sponsor_member_id')) {
                    $table->foreignId('sponsor_member_id')->nullable()->after('member_id')->constrained('kams_members')->nullOnDelete();
                }
                if (! Schema::hasColumn('kams_candidates', 'sponsor_name')) {
                    $table->string('sponsor_name')->nullable()->after('source');
                }
            });
        }

        if (Schema::hasTable('kams_observation_days') && ! Schema::hasColumn('kams_observation_days', 'sponsor_member_id')) {
            Schema::table('kams_observation_days', function (Blueprint $table) {
                $table->foreignId('sponsor_member_id')->nullable()->after('supervisor_member_id')->constrained('kams_members')->nullOnDelete();
            });
        }
    }
};
