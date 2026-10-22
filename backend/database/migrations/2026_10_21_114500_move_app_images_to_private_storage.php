<?php

use App\Modules\Kams\Services\PeoplePhotoStorage;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PeoplePhotoStorage::class)->migratePublicCopies();
    }

    public function down(): void
    {
        // Images stay on the private disk.
    }
};
