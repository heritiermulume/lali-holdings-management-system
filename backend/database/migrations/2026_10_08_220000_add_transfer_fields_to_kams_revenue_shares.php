<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_revenue_shares', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('posted_at');
            $table->foreignId('transfer_entry_id')
                ->nullable()
                ->after('paid_at')
                ->constrained('kams_treasury_entries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kams_revenue_shares', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_entry_id');
            $table->dropColumn('paid_at');
        });
    }
};
