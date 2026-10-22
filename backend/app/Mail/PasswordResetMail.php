<?php

namespace App\Mail;

use App\Mail\Concerns\PreventsReplies;
use App\Models\User;
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
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';

        return $this->automaticEnvelope($locale === 'en'
            ? 'Reset your LHMS password'
            : 'Réinitialisation de votre mot de passe LHMS');
    }

    public function content(): Content
    {
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';
        $frontend = rtrim((string) config('lhms.frontend_url'), '/');
        $resetUrl = $frontend.'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return new Content(
            view: 'emails.password-reset',
            with: [
                'user' => $this->user,
                'locale' => $locale,
                'resetUrl' => $resetUrl,
                'expiresMinutes' => (int) config('auth.passwords.users.expire', 60),
            ],
        );
    }
}
