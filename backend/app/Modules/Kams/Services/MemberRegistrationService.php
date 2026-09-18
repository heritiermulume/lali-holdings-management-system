<?php

namespace App\Modules\Kams\Services;

use App\Core\Support\AuditLogger;
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

    public function registerFromObservation(KamsObservationDay $observation, string $locale = 'fr'): KamsMember
    {
        $candidate = $observation->candidate()->firstOrFail();

        if ($candidate->member_id) {
            return $candidate->member()->firstOrFail();
        }

        return DB::transaction(function () use ($candidate, $observation, $locale) {
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
                'trainer_name' => $observation->supervisor_name,
                'rank' => 'Distributor',
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

            AuditLogger::record('register_member', $member, [], $member->toArray());
            $credentials = $this->accounts->provision($member);
            $this->wallets->ensureWallet($member);
            $this->communications->notifyFinalApproval($candidate, $member->fresh('user'), $locale, $credentials);

            return $member->fresh(['office', 'user']);
        });
    }

    public function applyProfileChange(KamsMember $member, string $field, mixed $value, ?string $reason, bool $requiresApproval = false): void
    {
        $tracked = ['office_id', 'trainer_name', 'trainer_user_id', 'manager_name', 'team_name', 'rank', 'role_id', 'status', 'phone', 'address'];

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

        AuditLogger::record('correct_member', $member, [$field => $old], [$field => $new, 'reason' => $reason]);
    }
}
