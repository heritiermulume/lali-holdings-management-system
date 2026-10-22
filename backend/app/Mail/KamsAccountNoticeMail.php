<?php

namespace App\Mail;

use App\Mail\Concerns\PreventsReplies;
use App\Models\User;
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
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';

        return new Content(
            view: 'emails.kams-account-notice',
            with: [
                'user' => $this->user,
                'locale' => $locale,
                'payload' => $this->payload,
                'title' => $this->subjectLine(),
            ],
        );
    }

    private function subjectLine(): string
    {
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';
        $confirm = ($this->payload['purpose'] ?? '') === 'confirm';
        $movement = (string) ($this->payload['movement'] ?? 'deposit');

        return match (true) {
            $confirm && $movement === 'withdrawal' => $locale === 'en'
                ? 'Withdrawal request to confirm'
                : 'Demande de retrait à confirmer',
            $confirm && $movement === 'transfer' => $locale === 'en'
                ? 'Transfer to confirm'
                : 'Transfert à confirmer',
            $confirm => $locale === 'en'
                ? 'Deposit request to confirm'
                : 'Demande de dépôt à confirmer',
            $movement === 'withdrawal' => $locale === 'en'
                ? 'Withdrawal recorded on your account'
                : 'Retrait enregistré sur votre compte',
            default => $locale === 'en'
                ? 'Deposit recorded on your account'
                : 'Dépôt enregistré sur votre compte',
        };
    }
}
