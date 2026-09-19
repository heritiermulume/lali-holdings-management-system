<?php

namespace App\Modules\Kams\Services;

use App\Modules\Kams\Models\KamsCandidate;
use App\Modules\Kams\Models\KamsCommunication;
use App\Modules\Kams\Models\KamsMember;
use Illuminate\Database\Eloquent\Model;

class RecruitmentCommunicationService
{
    public function notifyApplicationReceived(KamsCandidate $candidate, string $locale = 'fr'): void
    {
        $this->sendPair($candidate, 'application_received', 'recruitment', $locale, [
            'candidate' => [
                'fr' => ['subject' => 'Candidature reçue', 'body' => "Bonjour {$candidate->full_name}, votre candidature {$candidate->candidate_id} a bien été reçue. Nous vous tiendrons informé(e) des prochaines étapes."],
                'en' => ['subject' => 'Application received', 'body' => "Hello {$candidate->full_name}, your application {$candidate->candidate_id} has been received. We will keep you informed of the next steps."],
            ],
            'guardian' => [
                'fr' => ['subject' => 'Candidature de '.$candidate->full_name, 'body' => "La candidature de {$candidate->full_name} ({$candidate->candidate_id}) a été reçue par King Attitude."],
                'en' => ['subject' => $candidate->full_name.' application', 'body' => "The application of {$candidate->full_name} ({$candidate->candidate_id}) has been received by King Attitude."],
            ],
        ]);
    }

    public function notifyInterviewPassed(KamsCandidate $candidate, string $locale = 'fr'): void
    {
        $this->sendPair($candidate, 'interview_passed', 'recruitment', $locale, [
            'candidate' => [
                'fr' => ['subject' => 'Entretien réussi', 'body' => "Félicitations {$candidate->full_name}. Vous êtes admis(e) à la journée d'observation (07h30 – 17h30). Il s'agit d'une journée d'évaluation, non rémunérée, et ce n'est pas une offre d'emploi."],
                'en' => ['subject' => 'Interview passed', 'body' => "Congratulations {$candidate->full_name}. You are admitted to Observation Day (07:30 – 17:30). This is an unpaid full-day evaluation and not a job offer."],
            ],
            'guardian' => [
                'fr' => ['subject' => 'Entretien réussi', 'body' => "{$candidate->full_name} a réussi l'entretien et est convoqué(e) à une journée d'observation."],
                'en' => ['subject' => 'Interview passed', 'body' => "{$candidate->full_name} passed the interview and is invited to Observation Day."],
            ],
        ]);
    }

    public function notifyFinalApproval(KamsCandidate $candidate, KamsMember $member, string $locale = 'fr', array $credentials = []): void
    {
        $login = $credentials['email'] ?? $member->account['email'] ?? null;
        $password = $credentials['password'] ?? $member->login_password;
        $access = $locale === 'en'
            ? ($login ? " Login: {$login} / Password: {$password}. These credentials were also sent by email." : '')
            : ($login ? " Identifiant : {$login} / Mot de passe : {$password}. Ces identifiants ont aussi été envoyés par e-mail." : '');

        $this->sendPair($candidate, 'final_approval', 'recruitment', $locale, [
            'candidate' => [
                'fr' => ['subject' => 'Bienvenue chez King Attitude', 'body' => "Bienvenue {$candidate->full_name}. Vous êtes enregistré(e) comme membre {$member->member_code}.{$access}"],
                'en' => ['subject' => 'Welcome to King Attitude', 'body' => "Welcome {$candidate->full_name}. You are registered as member {$member->member_code}.{$access}"],
            ],
            'guardian' => [
                'fr' => ['subject' => 'Confirmation d\'adhésion', 'body' => "{$candidate->full_name} a été approuvé(e) et enregistré(e) comme membre King Attitude ({$member->member_code})."],
                'en' => ['subject' => 'Membership confirmation', 'body' => "{$candidate->full_name} has been approved and registered as a King Attitude member ({$member->member_code})."],
            ],
        ], $member);
    }

    public function notifyFirstWeekCompleted(KamsMember $member, string $locale = 'fr'): void
    {
        $this->sendPair($member, 'first_week_completed', 'recruitment', $locale, [
            'candidate' => [
                'fr' => ['subject' => 'Première semaine terminée', 'body' => "Bonjour {$member->full_name}, votre première semaine est terminée. Nous restons à vos côtés pour la suite."],
                'en' => ['subject' => 'First week completed', 'body' => "Hello {$member->full_name}, your first week is complete. We remain available for the next steps."],
            ],
            'guardian' => [
                'fr' => ['subject' => 'Suivi première semaine', 'body' => "La première semaine de {$member->full_name} chez King Attitude est terminée."],
                'en' => ['subject' => 'First week update', 'body' => "{$member->full_name}'s first week at King Attitude is complete."],
            ],
        ]);
    }

    public function notifyMigration(KamsMember $member, string $messageType, string $locale = 'fr', ?string $extra = null, array $credentials = []): void
    {
        $login = $credentials['email'] ?? $member->account['email'] ?? null;
        $password = $credentials['password'] ?? $member->login_password;
        $accessFr = $login
            ? " Identifiant : {$login} / Mot de passe : {$password}. Ces identifiants ont aussi été envoyés par e-mail."
            : '';
        $accessEn = $login
            ? " Login: {$login} / Password: {$password}. These credentials were also sent by email."
            : '';
        $missing = $extra ? " {$extra}" : '';

        $templates = [
            'profile_verification' => [
                'fr' => ['subject' => 'Vérification de profil', 'body' => "Bonjour {$member->full_name}, votre profil membre {$member->member_code} a été importé et nécessite une vérification.{$accessFr}"],
                'en' => ['subject' => 'Profile verification', 'body' => "Hello {$member->full_name}, your member profile {$member->member_code} was imported and needs verification.{$accessEn}"],
            ],
            'missing_information' => [
                'fr' => ['subject' => 'Informations manquantes', 'body' => "Bonjour {$member->full_name}, des informations manquent sur votre profil migré.{$missing}{$accessFr}"],
                'en' => ['subject' => 'Missing information', 'body' => "Hello {$member->full_name}, some information is missing on your migrated profile.{$missing}{$accessEn}"],
            ],
            'migration_confirmation' => [
                'fr' => ['subject' => 'Migration confirmée', 'body' => "Votre profil membre {$member->member_code} est désormais actif dans le nouveau système. Votre date d'adhésion originale reste inchangée."],
                'en' => ['subject' => 'Migration confirmed', 'body' => "Your member profile {$member->member_code} is now active in the new system. Your original join date is unchanged."],
            ],
        ];

        $copy = $templates[$messageType][$locale] ?? $templates[$messageType]['fr'];

        $this->log($member, 'member', $member->full_name, $member->phone, $messageType, $messageType, $locale, 'migration', $copy['subject'], $copy['body']);
    }

    /**
     * @param  array<string, array<string, array{subject: string, body: string}>>  $copy
     */
    private function sendPair(Model $record, string $trigger, string $category, string $locale, array $copy, ?Model $also = null): void
    {
        $candidateCopy = $copy['candidate'][$locale] ?? $copy['candidate']['fr'];
        $guardianCopy = $copy['guardian'][$locale] ?? $copy['guardian']['fr'];

        $name = $record->full_name;
        $phone = $record->phone;
        $guardian = $record->guardian_name;
        $guardianPhone = $record->guardian_phone;
        $companyId = (int) $record->company_id;

        $this->log($record, 'candidate', $name, $phone, $trigger, $trigger, $locale, $category, $candidateCopy['subject'], $candidateCopy['body']);

        if ($guardian || $guardianPhone) {
            $this->log($record, 'guardian', $guardian, $guardianPhone, $trigger, $trigger, $locale, $category, $guardianCopy['subject'], $guardianCopy['body']);
        }

        if ($also) {
            $this->log($also, 'candidate', $name, $phone, $trigger, $trigger, $locale, $category, $candidateCopy['subject'], $candidateCopy['body']);
        }
    }

    private function log(
        Model $record,
        string $recipientType,
        ?string $recipientName,
        ?string $recipientContact,
        string $messageType,
        string $trigger,
        string $locale,
        string $category,
        string $subject,
        string $body,
    ): void {
        KamsCommunication::query()->create([
            'company_id' => $record->company_id,
            'communicable_type' => $record::class,
            'communicable_id' => $record->getKey(),
            'recipient_type' => $recipientType,
            'recipient_name' => $recipientName,
            'recipient_contact' => $recipientContact,
            'message_type' => $messageType,
            'trigger' => $trigger,
            'channel' => 'logged',
            'locale' => $locale,
            'category' => $category,
            'subject' => $subject,
            'body' => $body,
            'delivery_status' => 'logged',
            'sent_at' => now(),
        ]);
    }
}
