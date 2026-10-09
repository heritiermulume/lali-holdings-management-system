<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Historically added sponsor_member_id to observation days.
 * The parrain concept was removed — encadreur (supervisor/coach) is the only upline.
 * Kept as a no-op so already-applied migration history stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
