<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kams_client_warehouse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('kams_clients')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('kams_depots')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'warehouse_id']);
        });

        if (Schema::hasColumn('kams_clients', 'warehouse_id')) {
            $rows = DB::table('kams_clients')
                ->whereNotNull('warehouse_id')
                ->get(['id', 'warehouse_id']);

            foreach ($rows as $row) {
                DB::table('kams_client_warehouse')->insertOrIgnore([
                    'client_id' => $row->id,
                    'warehouse_id' => $row->warehouse_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('kams_clients', function (Blueprint $table) {
                $table->dropConstrainedForeignId('warehouse_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('kams_clients', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('kind')
                ->constrained('kams_depots')
                ->nullOnDelete();
        });

        $rows = DB::table('kams_client_warehouse')
            ->orderBy('id')
            ->get(['client_id', 'warehouse_id']);

        foreach ($rows->groupBy('client_id') as $clientId => $links) {
            DB::table('kams_clients')
                ->where('id', $clientId)
                ->update(['warehouse_id' => $links->first()->warehouse_id]);
        }

        Schema::dropIfExists('kams_client_warehouse');
    }
};
