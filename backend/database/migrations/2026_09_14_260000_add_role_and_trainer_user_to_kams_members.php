<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_members', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('rank')->constrained('roles')->nullOnDelete();
            $table->foreignId('trainer_user_id')->nullable()->after('trainer_name')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('trainer_user_id');
        });
    }
};
