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

class FinanceRequestDecisionMail extends Mailable
{
    use PreventsReplies, Queueable, SerializesModels;

    /**
     * @param  array{decision: string, type: string, amount: float, notes?: string|null, reason?: string|null, member_name?: string|null}  $payload
     */
    public function __construct(
        public User $user,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $approved = ($this->payload['decision'] ?? '') === 'approve';

        return $this->automaticEnvelope($approved
            ? MailLocale::text(
                'Votre demande financière a été acceptée',
                'Your finance request was approved',
                'Ombi lako la fedha limekubaliwa',
                $this->user->locale,
            )
            : MailLocale::text(
                'Votre demande financière a été refusée',
                'Your finance request was rejected',
                'Ombi lako la fedha limekataliwa',
                $this->user->locale,
            ));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.finance-request-decision',
            with: [
                'user' => $this->user,
                'locale' => MailLocale::for($this->user->locale),
                'payload' => $this->payload,
            ],
        );
    }
}
