<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->string('gender', 16)->nullable()->after('full_name');
            $table->date('birth_date')->nullable()->after('gender');
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->string('gender', 16)->nullable()->after('full_name');
            $table->date('birth_date')->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birth_date']);
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birth_date']);
        });
    }
};
