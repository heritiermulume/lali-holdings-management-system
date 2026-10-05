<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 16)->nullable()->after('last_name');
        });

        if (Schema::hasTable('kams_members')) {
            DB::table('kams_members')
                ->whereNotNull('user_id')
                ->whereIn('gender', ['male', 'female'])
                ->orderBy('id')
                ->get(['user_id', 'gender'])
                ->each(function ($member) {
                    DB::table('users')
                        ->where('id', $member->user_id)
                        ->whereNull('gender')
                        ->update(['gender' => $member->gender]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
