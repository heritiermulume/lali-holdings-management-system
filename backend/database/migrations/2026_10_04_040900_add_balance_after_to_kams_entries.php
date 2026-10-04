<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            $table->decimal('balance_after', 14, 2)->nullable()->after('amount');
        });
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            $table->decimal('balance_after', 14, 2)->nullable()->after('amount');
        });

        $this->backfill('kams_savings_entries', 'member_id');
        $this->backfill('kams_treasury_entries', 'account_id');
    }

    public function down(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            $table->dropColumn('balance_after');
        });
        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            $table->dropColumn('balance_after');
        });
    }

    private function backfill(string $table, string $group): void
    {
        $groups = DB::table($table)
            ->where(function ($query) {
                $query->where('status', 'posted')->orWhereNull('status');
            })
            ->distinct()
            ->pluck($group);

        foreach ($groups as $id) {
            $rows = DB::table($table)
                ->where($group, $id)
                ->where(function ($query) {
                    $query->where('status', 'posted')->orWhereNull('status');
                })
                ->orderBy('updated_at')
                ->orderBy('id')
                ->get(['id', 'amount']);
            $running = 0.0;
            foreach ($rows as $row) {
                $running = round($running + (float) $row->amount, 2);
                DB::table($table)->where('id', $row->id)->update(['balance_after' => $running]);
            }
        }
    }
};
