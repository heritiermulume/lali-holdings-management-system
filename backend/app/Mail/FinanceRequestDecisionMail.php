<?php

namespace App\Mail;

use App\Mail\Concerns\PreventsReplies;
use App\Models\User;
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
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';
        $approved = ($this->payload['decision'] ?? '') === 'approve';

        return $this->automaticEnvelope($locale === 'en'
            ? ($approved ? 'Your finance request was approved' : 'Your finance request was rejected')
            : ($approved ? 'Votre demande financière a été acceptée' : 'Votre demande financière a été refusée'));
    }

    public function content(): Content
    {
        $locale = $this->user->locale === 'en' ? 'en' : 'fr';

        return new Content(
            view: 'emails.finance-request-decision',
            with: [
                'user' => $this->user,
                'locale' => $locale,
                'payload' => $this->payload,
            ],
        );
    }
}
