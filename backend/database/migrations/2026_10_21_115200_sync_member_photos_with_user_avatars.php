<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $memberPhotos = DB::table('kams_members')
            ->whereNotNull('user_id')
            ->whereNotNull('photo_path')
            ->whereNull('deleted_at')
            ->get(['user_id', 'photo_path']);

        foreach ($memberPhotos as $row) {
            DB::table('users')
                ->where('id', $row->user_id)
                ->whereNull('deleted_at')
                ->where(function ($query) {
                    $query->whereNull('avatar_path')->orWhere('avatar_path', '');
                })
                ->update(['avatar_path' => $row->photo_path]);
        }

        $userPhotos = DB::table('users')
            ->whereNotNull('avatar_path')
            ->whereNull('deleted_at')
            ->get(['id', 'avatar_path']);

        foreach ($userPhotos as $row) {
            DB::table('kams_members')
                ->where('user_id', $row->id)
                ->whereNull('deleted_at')
                ->where(function ($query) {
                    $query->whereNull('photo_path')->orWhere('photo_path', '');
                })
                ->update(['photo_path' => $row->avatar_path]);
        }
    }

    public function down(): void
    {
        // Shared photo paths are left in place.
    }
};
