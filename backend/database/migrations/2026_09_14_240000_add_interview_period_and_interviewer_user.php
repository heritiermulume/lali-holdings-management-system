<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_interviews', function (Blueprint $table) {
            $table->dateTime('interview_starts_at')->nullable()->after('interview_date');
            $table->dateTime('interview_ends_at')->nullable()->after('interview_starts_at');
            $table->foreignId('interviewer_user_id')->nullable()->after('interviewer_name')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_interviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('interviewer_user_id');
            $table->dropColumn(['interview_starts_at', 'interview_ends_at']);
        });
    }
};
