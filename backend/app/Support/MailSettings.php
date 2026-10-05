<?php

namespace App\Support;

use Illuminate\Support\Facades\Mail;

class MailSettings
{
    /**
     * Apply mail transport / from settings stored in Administration → General.
     */
    public static function apply(): void
    {
        try {
            $fromAddress = trim(SettingsCatalog::string('general', 'mail_from_address'));
            if ($fromAddress === '') {
                $fromAddress = trim(SettingsCatalog::string('general', 'support_email'));
            }

            $fromName = trim(SettingsCatalog::string('general', 'mail_from_name'));
            if ($fromName === '') {
                $fromName = SettingsCatalog::string('general', 'group_name', (string) config('app.name'));
            }

            if ($fromAddress !== '' && filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
                config([
                    'mail.from.address' => $fromAddress,
                    'mail.from.name' => $fromName !== '' ? $fromName : (string) config('app.name'),
                ]);
            }

            $host = trim(SettingsCatalog::string('general', 'mail_host'));
            if ($host === '') {
                return;
            }

            $port = (int) SettingsCatalog::number('general', 'mail_port', 587);
            if ($port <= 0) {
                $port = 587;
            }

            $encryption = strtolower(trim(SettingsCatalog::string('general', 'mail_encryption', 'tls')));
            $scheme = match ($encryption) {
                'ssl', 'smtps' => 'smtps',
                'none' => 'smtp',
                default => ($port === 465 ? 'smtps' : 'smtp'),
            };

            $username = trim(SettingsCatalog::string('general', 'mail_username'));
            $password = SettingsCatalog::string('general', 'mail_password');

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $host,
                'mail.mailers.smtp.port' => $port,
                'mail.mailers.smtp.username' => $username !== '' ? $username : null,
                'mail.mailers.smtp.password' => $password !== '' ? $password : null,
                'mail.mailers.smtp.scheme' => $scheme,
            ]);

            if (app()->bound('mail.manager')) {
                Mail::forgetMailers();
            }
        } catch (\Throwable) {
            // Settings table may be unavailable during install / early boot.
        }
    }
}
