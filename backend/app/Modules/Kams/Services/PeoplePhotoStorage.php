<?php

namespace App\Modules\Kams\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PeoplePhotoStorage
{
    public const DISK = 'local';

    public const DIRECTORY = 'kams/photos';

    public const DIRECTORIES = [
        'kams/photos',
        'avatars',
        'kams/products',
        'kams/assets',
    ];

    public function store(UploadedFile $file, string $directory = self::DIRECTORY): string
    {
        $directory = trim($directory, '/');
        abort_unless($this->isManagedDirectory($directory), 422);

        return $file->store($directory, self::DISK);
    }

    public function storeReplacing(?string $currentPath, UploadedFile $file, string $directory = self::DIRECTORY): string
    {
        $path = $this->store($file, $directory);
        if ($currentPath && $currentPath !== $path) {
            $this->delete($currentPath);
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if (! $this->isManagedPath($path)) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
        Storage::disk('public')->delete($path);
    }

    public function url(?string $path): ?string
    {
        if (! $this->isManagedPath($path)) {
            return null;
        }

        $this->ensurePrivate($path);

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $absolute = Storage::disk(self::DISK)->temporaryUrl($path, now()->addHours(12));
        $relativePath = parse_url($absolute, PHP_URL_PATH) ?: '';
        $query = parse_url($absolute, PHP_URL_QUERY);

        return $query ? $relativePath.'?'.$query : $relativePath;
    }

    public function urlForStoredPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if ($this->isManagedPath($path)) {
            return $this->url($path);
        }

        return '/storage/'.$path;
    }

    public function urlFromPaths(?string ...$paths): ?string
    {
        foreach ($paths as $path) {
            if ($url = $this->url($path)) {
                return $url;
            }
        }

        foreach ($paths as $path) {
            if ($path) {
                return $this->urlForStoredPath($path);
            }
        }

        return null;
    }

    public function migratePublicCopies(): int
    {
        $public = Storage::disk('public');
        $moved = 0;

        foreach (self::DIRECTORIES as $directory) {
            if (! $public->exists($directory)) {
                continue;
            }

            foreach ($public->allFiles($directory) as $file) {
                $this->ensurePrivate($file);
                $moved++;
            }
        }

        return $moved;
    }

    private function ensurePrivate(string $path): void
    {
        if (! $this->isManagedPath($path)) {
            return;
        }

        $private = Storage::disk(self::DISK);
        $public = Storage::disk('public');

        if (! $private->exists($path) && $public->exists($path)) {
            $private->put($path, $public->get($path));
        }

        if ($public->exists($path)) {
            $public->delete($path);
        }
    }

    private function isManagedDirectory(string $directory): bool
    {
        return in_array($directory, self::DIRECTORIES, true);
    }

    private function isManagedPath(?string $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        foreach (self::DIRECTORIES as $directory) {
            if (str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return false;
    }
}
