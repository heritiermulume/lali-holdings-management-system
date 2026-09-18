<?php

use App\Modules\Kams\Models\KamsMember;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_savings_entries', 'requested_by')) {
                $table->foreignId('requested_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('kams_savings_entries', 'performed_by')) {
                $table->foreignId('performed_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('kams_treasury_entries', 'requested_by')) {
                $table->foreignId('requested_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('kams_treasury_entries', 'performed_by')) {
                $table->foreignId('performed_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            }
        });

        foreach (DB::table('kams_savings_entries')->select('id', 'created_by', 'status')->get() as $row) {
            DB::table('kams_savings_entries')->where('id', $row->id)->update([
                'requested_by' => $row->created_by,
                'performed_by' => $row->status === 'pending' ? null : $row->created_by,
            ]);
        }

        $memberUsers = KamsMember::query()->whereNotNull('user_id')->pluck('user_id', 'id');
        foreach (DB::table('kams_treasury_entries')->select('id', 'created_by', 'member_id')->get() as $row) {
            $requested = $row->member_id ? ($memberUsers[$row->member_id] ?? $row->created_by) : $row->created_by;
            DB::table('kams_treasury_entries')->where('id', $row->id)->update([
                'requested_by' => $requested,
                'performed_by' => $row->created_by,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('kams_savings_entries', function (Blueprint $table) {
            if (Schema::hasColumn('kams_savings_entries', 'performed_by')) {
                $table->dropConstrainedForeignId('performed_by');
            }
            if (Schema::hasColumn('kams_savings_entries', 'requested_by')) {
                $table->dropConstrainedForeignId('requested_by');
            }
        });

        Schema::table('kams_treasury_entries', function (Blueprint $table) {
            if (Schema::hasColumn('kams_treasury_entries', 'performed_by')) {
                $table->dropConstrainedForeignId('performed_by');
            }
            if (Schema::hasColumn('kams_treasury_entries', 'requested_by')) {
                $table->dropConstrainedForeignId('requested_by');
            }
        });
    }
};
