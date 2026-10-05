<?php

namespace App\Core\Support;

use App\Support\SettingsCatalog;

class InterviewHours
{
    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function bounds(): array
    {
        $raw = SettingsCatalog::string('kams', 'interview_hours');
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $raw, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        }

        $fallback = (string) (SettingsCatalog::meta('kams', 'interview_hours')['default'] ?? '12:00-16:00');
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $fallback, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        }

        return [12, 0, 16, 0];
    }

    public static function label(): string
    {
        [$sh, $sm, $eh, $em] = self::bounds();

        return sprintf('%02d:%02d – %02d:%02d', $sh, $sm, $eh, $em);
    }
}
