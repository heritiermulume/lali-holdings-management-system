<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'primary_department_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['primary_department_id']);
                $table->dropColumn('primary_department_id');
            });
        }

        Schema::dropIfExists('departments');

        if (Schema::hasTable('user_scopes')) {
            DB::table('user_scopes')->where('scope_type', 'department')->delete();

            DB::statement("ALTER TABLE user_scopes MODIFY scope_type ENUM('group', 'country', 'city', 'company', 'office', 'module') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('user_scopes')) {
            DB::statement("ALTER TABLE user_scopes MODIFY scope_type ENUM('group', 'country', 'city', 'company', 'office', 'department', 'module') NOT NULL");
        }

        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('code', 32);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('users', 'primary_department_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('primary_department_id')->nullable()->after('primary_office_id');
                $table->foreign('primary_department_id')->references('id')->on('departments')->nullOnDelete();
            });
        }
    }
};
