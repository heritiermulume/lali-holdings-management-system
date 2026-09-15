<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_members', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('candidate_id')->constrained('users')->nullOnDelete();
            $table->text('login_password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropColumn('login_password');
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
