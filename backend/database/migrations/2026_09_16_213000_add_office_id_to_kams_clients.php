<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_clients', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });

        $clients = DB::table('kams_clients')->whereNull('office_id')->get(['id', 'company_id']);
        foreach ($clients as $client) {
            $officeId = DB::table('offices')
                ->where('company_id', $client->company_id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->value('id');

            if ($officeId) {
                DB::table('kams_clients')->where('id', $client->id)->update(['office_id' => $officeId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('kams_clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('office_id');
        });
    }
};
