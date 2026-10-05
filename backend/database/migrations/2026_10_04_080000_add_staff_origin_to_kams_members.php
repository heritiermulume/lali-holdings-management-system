<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE kams_members MODIFY COLUMN origin ENUM('new_recruit', 'migrated', 'staff') NOT NULL DEFAULT 'new_recruit'");
    }

    public function down(): void
    {
        DB::table('kams_members')->where('origin', 'staff')->update(['origin' => 'migrated']);
        DB::statement("ALTER TABLE kams_members MODIFY COLUMN origin ENUM('new_recruit', 'migrated') NOT NULL DEFAULT 'new_recruit'");
    }
};
