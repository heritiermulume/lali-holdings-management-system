<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_clients', function (Blueprint $table) {
            $table->string('kind', 20)->default('client')->after('office_id');
            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('kams_clients', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
