<?php

namespace App\Mail;

use App\Mail\Concerns\PreventsReplies;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MailTestMail extends Mailable
{
    use PreventsReplies, Queueable, SerializesModels;

    public function __construct(
        public string $messageLocale = 'fr',
    ) {}

    public function envelope(): Envelope
    {
        return $this->automaticEnvelope($this->messageLocale === 'en'
            ? 'LHMS mail configuration test'
            : 'Test de configuration e-mail LHMS');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mail-test',
            with: [
                'locale' => $this->messageLocale === 'en' ? 'en' : 'fr',
            ],
        );
    }
}
