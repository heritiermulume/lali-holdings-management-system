<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MailTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $messageLocale = 'fr',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->messageLocale === 'en'
                ? 'LHMS mail configuration test'
                : 'Test de configuration e-mail LHMS',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->messageLocale === 'en'
                ? '<p>This is a test message from LHMS. Your mail settings are working.</p>'
                : '<p>Ceci est un message de test depuis LHMS. Votre configuration e-mail fonctionne.</p>',
        );
    }
}
