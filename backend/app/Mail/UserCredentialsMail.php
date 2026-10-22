<?php

namespace App\Mail;

use App\Mail\Concerns\PreventsReplies;
use App\Models\User;
use App\Support\MailLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserCredentialsMail extends Mailable
{
    use PreventsReplies, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $password,
    ) {}

    public function envelope(): Envelope
    {
        return $this->automaticEnvelope(MailLocale::text(
            'Vos identifiants de connexion LHMS',
            'Your LHMS login credentials',
            'Taarifa zako za kuingia LHMS',
            $this->user->locale,
        ));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-credentials',
            with: [
                'user' => $this->user,
                'password' => $this->password,
                'locale' => MailLocale::for($this->user->locale),
                'loginUrl' => rtrim((string) config('lhms.frontend_url', config('app.url')), '/').'/login',
            ],
        );
    }
}
