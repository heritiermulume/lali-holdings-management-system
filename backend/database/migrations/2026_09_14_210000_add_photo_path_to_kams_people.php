<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('email');
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('kams_candidates', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });

        Schema::table('kams_members', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
