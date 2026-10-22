<?php

namespace App\Mail\Concerns;

use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

trait PreventsReplies
{
    protected function automaticEnvelope(string $subject): Envelope
    {
        $from = (string) config('mail.from.address');
        $domain = str_contains($from, '@') ? substr($from, strrpos($from, '@') + 1) : '';
        $replyTo = $domain !== '' ? ['noreply@'.$domain] : [];

        return new Envelope(
            subject: $subject,
            replyTo: $replyTo,
            using: [
                function (Email $email): void {
                    $headers = $email->getHeaders();
                    $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                    $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
                    $headers->addTextHeader('Precedence', 'bulk');
                },
            ],
        );
    }
}
