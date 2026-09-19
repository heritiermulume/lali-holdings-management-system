<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $password,
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';

        return new Envelope(
            subject: $locale === 'en'
                ? 'Your LHMS login credentials'
                : 'Vos identifiants de connexion LHMS',
        );
    }

    public function content(): Content
    {
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';

        return new Content(
            view: 'emails.user-credentials',
            with: [
                'user' => $this->user,
                'password' => $this->password,
                'locale' => $locale,
                'loginUrl' => rtrim((string) config('app.url'), '/').'/login',
            ],
        );
    }
}
