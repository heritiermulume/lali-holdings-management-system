<?php

namespace App\Mail;

use App\Mail\Concerns\PreventsReplies;
use App\Support\MailLocale;
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
        return $this->automaticEnvelope(MailLocale::text(
            'Test de configuration e-mail LHMS',
            'LHMS mail configuration test',
            'Jaribio la usanidi wa barua pepe wa LHMS',
            $this->messageLocale,
        ));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mail-test',
            with: [
                'locale' => MailLocale::for($this->messageLocale),
            ],
        );
    }
}
