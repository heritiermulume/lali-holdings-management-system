<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('office_id')->constrained('kams_members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
        });
    }
};
