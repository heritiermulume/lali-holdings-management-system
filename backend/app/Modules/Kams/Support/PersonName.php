<?php

namespace App\Modules\Kams\Support;

class PersonName
{
    /**
     * @return array{first_name: string, last_name: string, full_name: string}
     */
    public static function fromParts(?string $firstName, ?string $lastName): array
    {
        $first = trim((string) $firstName);
        $last = trim((string) $lastName);
        $full = trim($first.' '.$last);

        return [
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $full,
        ];
    }

    /**
     * @return array{first_name: string, last_name: string, full_name: string}
     */
    public static function fromFullName(?string $fullName): array
    {
        $full = trim((string) $fullName);
        if ($full === '') {
            return ['first_name' => '', 'last_name' => '', 'full_name' => ''];
        }

        $parts = preg_split('/\s+/', $full, 2) ?: [];

        return self::fromParts($parts[0] ?? $full, $parts[1] ?? '');
    }
}
