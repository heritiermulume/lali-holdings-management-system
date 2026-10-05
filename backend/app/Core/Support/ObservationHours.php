<?php

namespace App\Core\Support;

use App\Support\SettingsCatalog;
use Carbon\CarbonInterface;

class ObservationHours
{
    /**
     * @return array{0: int, 1: int, 2: int, 3: int} [startHour, startMinute, endHour, endMinute]
     */
    public static function bounds(): array
    {
        $raw = SettingsCatalog::string('kams', 'observation_hours');
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $raw, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        }

        $fallback = (string) (SettingsCatalog::meta('kams', 'observation_hours')['default'] ?? '07:30-17:30');
        if (preg_match('/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $fallback, $m)) {
            return [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4]];
        }

        return [7, 30, 17, 30];
    }

    public static function label(): string
    {
        [$sh, $sm, $eh, $em] = self::bounds();

        return sprintf('%02d:%02d – %02d:%02d', $sh, $sm, $eh, $em);
    }

    public static function applyTo(CarbonInterface $day): array
    {
        [$sh, $sm, $eh, $em] = self::bounds();

        return [
            $day->copy()->setTime($sh, $sm),
            $day->copy()->setTime($eh, $em),
        ];
    }
}
