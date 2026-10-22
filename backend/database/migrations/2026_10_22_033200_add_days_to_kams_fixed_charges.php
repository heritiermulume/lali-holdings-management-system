<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_fixed_charges', function (Blueprint $table) {
            $table->unsignedInteger('days')->default(30)->after('starts_on');
        });

        DB::table('kams_fixed_charges')->orderBy('id')->each(function (object $row): void {
            $days = match ($row->period ?? 'month') {
                'day' => 1,
                'week' => 7,
                'year' => 365,
                default => 30,
            };
            if ($row->starts_on && $row->ends_on) {
                $days = max(1, (int) abs(strtotime((string) $row->ends_on) - strtotime((string) $row->starts_on)) / 86400 + 1);
            }
            DB::table('kams_fixed_charges')->where('id', $row->id)->update(['days' => $days]);
        });
    }

    public function down(): void
    {
        Schema::table('kams_fixed_charges', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
