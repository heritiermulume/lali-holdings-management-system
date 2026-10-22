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

class PasswordResetMail extends Mailable
{
    use PreventsReplies, Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $token,
    ) {}

    public function envelope(): Envelope
    {
        return $this->automaticEnvelope(MailLocale::text(
            'Réinitialisation de votre mot de passe LHMS',
            'Reset your LHMS password',
            'Weka upya nenosiri lako la LHMS',
            $this->user->locale,
        ));
    }

    public function content(): Content
    {
        $frontend = rtrim((string) config('lhms.frontend_url'), '/');
        $resetUrl = $frontend.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return new Content(
            view: 'emails.password-reset',
            with: [
                'user' => $this->user,
                'locale' => MailLocale::for($this->user->locale),
                'resetUrl' => $resetUrl,
                'expiresMinutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}
