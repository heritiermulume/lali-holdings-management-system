<?php

namespace Tests\Unit;

use App\Modules\Kams\Services\PeoplePhotoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PeoplePhotoStorageTest extends TestCase
{
    public function test_it_stores_photos_on_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $path = (new PeoplePhotoStorage)->store(UploadedFile::fake()->image('face.jpg', 80, 80));

        $this->assertTrue(str_starts_with($path, 'kams/photos/'));
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_it_stores_avatars_on_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $path = (new PeoplePhotoStorage)->store(UploadedFile::fake()->image('avatar.jpg', 80, 80), 'avatars');

        $this->assertTrue(str_starts_with($path, 'avatars/'));
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_it_moves_legacy_public_photos_to_the_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $path = 'kams/photos/legacy.jpg';
        Storage::disk('public')->put($path, 'photo-bytes');

        $moved = (new PeoplePhotoStorage)->migratePublicCopies();

        $this->assertSame(1, $moved);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
}
