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

class KamsAccountNoticeMail extends Mailable
{
    use PreventsReplies, Queueable, SerializesModels;

    /**
     * @param  array{
     *     purpose: string,
     *     movement: string,
     *     amount: float,
     *     currency?: string|null,
     *     notes?: string|null,
     *     balance_after?: float|null,
     *     action_url?: string|null,
     *     office?: string|null,
     *     member_name?: string|null,
     *     wallet?: string|null
     * }  $payload
     */
    public function __construct(
        public User $user,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        return $this->automaticEnvelope($this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.kams-account-notice',
            with: [
                'user' => $this->user,
                'locale' => MailLocale::for($this->user->locale),
                'payload' => $this->payload,
                'title' => $this->subjectLine(),
            ],
        );
    }

    private function subjectLine(): string
    {
        $confirm = ($this->payload['purpose'] ?? '') === 'confirm';
        $movement = (string) ($this->payload['movement'] ?? 'deposit');

        return match (true) {
            $confirm && $movement === 'withdrawal' => MailLocale::text(
                'Demande de retrait à confirmer',
                'Withdrawal request to confirm',
                'Ombi la kutoa pesa la kuthibitishwa',
                $this->user->locale,
            ),
            $confirm && $movement === 'transfer' => MailLocale::text(
                'Transfert à confirmer',
                'Transfer to confirm',
                'Uhamisho wa kuthibitishwa',
                $this->user->locale,
            ),
            $confirm => MailLocale::text(
                'Demande de dépôt à confirmer',
                'Deposit request to confirm',
                'Ombi la kuweka pesa la kuthibitishwa',
                $this->user->locale,
            ),
            $movement === 'withdrawal' => MailLocale::text(
                'Retrait enregistré sur votre compte',
                'Withdrawal recorded on your account',
                'Utoaji umerekodiwa kwenye akaunti yako',
                $this->user->locale,
            ),
            default => MailLocale::text(
                'Dépôt enregistré sur votre compte',
                'Deposit recorded on your account',
                'Amana imerekodiwa kwenye akaunti yako',
                $this->user->locale,
            ),
        };
    }
}
