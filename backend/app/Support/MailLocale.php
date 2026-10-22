<?php

namespace App\Support;

class MailLocale
{
    public static function for(?string $locale): string
    {
        $code = strtolower(substr((string) $locale, 0, 2));

        return in_array($code, ['fr', 'en', 'sw'], true) ? $code : SettingsCatalog::defaultLocale();
    }

    public static function text(string $fr, string $en, string $sw, ?string $locale = null): string
    {
        return match (self::for($locale)) {
            'en' => $en,
            'sw' => $sw,
            default => $fr,
        };
    }
}
