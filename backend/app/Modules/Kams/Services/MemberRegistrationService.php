<?php

namespace App\Modules\Kams\Services;

use App\Support\SettingsCatalog;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsMemberHistory;
use App\Modules\Kams\Models\KamsObservationDay;
use Illuminate\Support\Facades\DB;

class MemberRegistrationService
{
    public function __construct(
        private readonly KamsIdentityService $identity,
        private readonly RecruitmentCommunicationService $communications,
        private readonly AgentAccountService $accounts,
        private readonly KamsAccountProvisioner $wallets,
    ) {}

    /**
     * @return array{member: KamsMember, credentials: array{email: ?string, phone: ?string, password: ?string, is_active: bool}}
     */
    public function registerFromObservation(KamsObservationDay $observation, string $locale = 'fr'): array
    {
        $candidate = $observation->candidate()->firstOrFail();

        if ($candidate->member_id) {
            $member = $candidate->member()->firstOrFail();

            return [
                'member' => $member->load(['office', 'user', 'role']),
                'credentials' => $this->accounts->provision($member),
            ];
        }

        return DB::transaction(function () use ($candidate, $observation, $locale) {
            $sponsor = $observation->sponsor()->first()
                ?: ($candidate->sponsor_member_id ? KamsMember::query()->find($candidate->sponsor_member_id) : null);
            $coach = $observation->supervisor()->first();

            $member = KamsMember::query()->create([
                'company_id' => $candidate->company_id,
                'office_id' => $observation->office_id ?: $candidate->office_id,
                'candidate_id' => $candidate->id,
                'member_code' => $this->identity->nextMemberCode((int) $candidate->company_id),
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'full_name' => $candidate->full_name,
                'gender' => $candidate->gender,
                'birth_date' => $candidate->birth_date,
                'phone' => $candidate->phone,
                'email' => $candidate->email,
                'photo_path' => $candidate->photo_path,
                'address' => $candidate->address,
                'guardian_name' => $candidate->guardian_name,
                'guardian_phone' => $candidate->guardian_phone,
                'trainer_name' => $sponsor?->full_name,
                'trainer_user_id' => $sponsor?->user_id,
                'coach_member_id' => $coach?->id,
                'coach_name' => $coach?->full_name ?: $observation->supervisor_name,
                'rank' => SettingsCatalog::string('kams', 'default_member_rank'),
                'origin' => 'new_recruit',
                'status' => 'active',
                'joined_on' => now()->toDateString(),
                'documents' => $candidate->documents,
            ]);

            $candidate->forceFill([
                'member_id' => $member->id,
                'status' => 'registered',
            ])->save();

            KamsMemberHistory::query()->create([
                'member_id' => $member->id,
                'field' => 'origin',
                'old_value' => null,
                'new_value' => 'new_recruit',
                'changed_by' => auth()->id(),
                'reason' => 'Registered from approved observation day',
                'changed_at' => now(),
            ]);

            $credentials = $this->accounts->provision($member);
            $this->wallets->ensureWallet($member);
            $fresh = $member->fresh(['office', 'user', 'role']);
            $this->communications->notifyFinalApproval($candidate, $fresh, $locale, $credentials);

            return [
                'member' => $fresh,
                'credentials' => $credentials,
            ];
        });
    }

    public function applyProfileChange(KamsMember $member, string $field, mixed $value, ?string $reason, bool $requiresApproval = false): void
    {
        $tracked = ['office_id', 'trainer_name', 'trainer_user_id', 'manager_name', 'manager_member_id', 'assistant_manager_member_id', 'fixed_salary', 'team_name', 'rank', 'role_id', 'status', 'phone', 'address'];

        if (! in_array($field, $tracked, true)) {
            $member->forceFill([$field => $value])->save();

            return;
        }

        $old = $member->getAttribute($field);
        $new = $value;

        if ((string) $old === (string) $new) {
            return;
        }

        $member->forceFill([$field => $new])->save();

        KamsMemberHistory::query()->create([
            'member_id' => $member->id,
            'field' => $field,
            'old_value' => $old === null ? null : (string) $old,
            'new_value' => $new === null ? null : (string) $new,
            'changed_by' => auth()->id(),
            'approved_by' => $requiresApproval ? auth()->id() : null,
            'reason' => $reason,
            'changed_at' => now(),
        ]);

    }
}
