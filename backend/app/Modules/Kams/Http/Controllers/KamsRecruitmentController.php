<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Core\Support\AuditLogger;
use App\Core\Support\RoleCatalog;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kams\Models\KamsCandidate;
use App\Modules\Kams\Models\KamsCommunication;
use App\Modules\Kams\Models\KamsInterview;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsMemberImport;
use App\Modules\Kams\Models\KamsMemberImportRow;
use App\Modules\Kams\Models\KamsMemberOpeningPosition;
use App\Modules\Kams\Models\KamsObservationDay;
use App\Modules\Kams\Services\AgentAccountService;
use App\Modules\Kams\Services\KamsAccountProvisioner;
use App\Modules\Kams\Services\KamsIdentityService;
use App\Modules\Kams\Services\MemberMigrationService;
use App\Modules\Kams\Services\MemberRegistrationService;
use App\Modules\Kams\Services\RecruitmentCommunicationService;
use App\Modules\Kams\Services\RecruitmentMetrics;
use App\Modules\Kams\Support\PersonName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class KamsRecruitmentController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly KamsIdentityService $identity,
        private readonly RecruitmentCommunicationService $communications,
        private readonly MemberRegistrationService $registration,
        private readonly MemberMigrationService $migration,
        private readonly RecruitmentMetrics $metrics,
        private readonly AgentAccountService $accounts,
        private readonly KamsAccountProvisioner $wallets,
    ) {}

    public function lookups(Request $request): JsonResponse
    {
        $companyIds = $request->user()->accessibleCompanyIds();

        $companies = Company::query()
            ->when(! $request->user()->isGroupAdmin(), fn ($q) => $q->whereIn('id', $companyIds ?: [0]))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $offices = Office::query()
            ->when(! $request->user()->isGroupAdmin(), function ($q) use ($request, $companyIds) {
                $officeIds = $request->user()->accessibleOfficeIds();
                $q->whereIn('company_id', $companyIds ?: [0]);
                if ($request->user()->isOfficeScoped()) {
                    $q->whereIn('id', $officeIds ?: [0]);
                }
            })
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'code']);

        $members = $this->scopedMembers($request)
            ->whereIn('status', ['active', 'verified', 'pending_verification'])
            ->orderBy('full_name')
            ->limit(500)
            ->get(['id', 'company_id', 'office_id', 'user_id', 'member_code', 'full_name', 'phone', 'role_id']);

        $users = User::query()
            ->where('is_active', true)
            ->when(! $request->user()->isGroupAdmin(), function ($q) use ($companyIds) {
                $q->where(function ($inner) use ($companyIds) {
                    $inner->whereIn('primary_company_id', $companyIds ?: [0])
                        ->orWhereHas('scopes', function ($scope) use ($companyIds) {
                            $scope->where('scope_type', 'company')->whereIn('scope_id', $companyIds ?: [0]);
                        });
                });
            })
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'email', 'primary_company_id']);

        $roles = Role::query()
            ->where('module_slug', 'kams')
            ->whereNotIn('slug', RoleCatalog::fullAccessSlugs())
            ->with('offices:id,name,code,company_id')
            ->orderByDesc('level')
            ->get();

        return $this->ok(compact('companies', 'offices', 'members', 'users', 'roles'));
    }

    public function dashboard(Request $request): JsonResponse
    {
        if ($request->filled('company_id')) {
            $this->assertCompany($request, (int) $request->company_id);
        }

        $companyId = $request->filled('company_id')
            ? (int) $request->company_id
            : ($request->user()->isGroupAdmin() ? null : $request->user()->primary_company_id);

        return $this->ok($this->metrics->recap(
            $request->string('group_by')->toString() ?: 'week',
            $companyId,
            $request->filled('office_id') ? (int) $request->office_id : null,
        ));
    }

    public function candidates(Request $request): JsonResponse
    {
        $query = $this->scopedCandidates($request)
            ->with(['office', 'sponsor', 'guardian', 'member', 'latestInterview', 'latestObservationDay'])
            ->whereNull('member_id')
            ->whereNotIn('status', ['registered', 'approved'])
            ->latest('applied_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 20)));
    }

    public function storeCandidate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'gender' => ['required', 'in:male,female'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email'],
            'photo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'address' => ['nullable', 'string'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:64'],
            'guardian_member_id' => ['nullable', 'exists:kams_members,id'],
            'source' => ['required', 'in:poster,qr_code,online_form,sponsorship,social_media,other'],
            'sponsor_name' => ['nullable', 'string', 'max:255'],
            'sponsor_member_id' => ['nullable', 'exists:kams_members,id'],
            'notes' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
        ]);

        $this->assertCompany($request, (int) $data['company_id']);
        $this->assertOfficeCompany($data['office_id'] ?? null, (int) $data['company_id']);
        $photoPath = $this->storePeoplePhoto($request);
        unset($data['photo']);
        $data = $this->hydrateLinkedPeople($data);
        $data = array_merge($data, PersonName::fromParts($data['first_name'], $data['last_name']));

        if ($photoPath) {
            $data['photo_path'] = $photoPath;
        }

        $candidate = KamsCandidate::query()->create([
            ...$data,
            'candidate_id' => $this->identity->nextCandidateId((int) $data['company_id']),
            'status' => 'received',
            'applied_at' => now(),
            'source' => $data['source'],
        ]);

        $this->communications->notifyApplicationReceived($candidate, $request->getPreferredLanguage() === 'en' ? 'en' : 'fr');
        AuditLogger::record('create', $candidate, [], $candidate->toArray());

        return $this->created($candidate->load(['office', 'sponsor', 'guardian']));
    }

    public function showCandidate(Request $request, KamsCandidate $candidate): JsonResponse
    {
        $this->assertCompany($request, (int) $candidate->company_id);

        return $this->ok($candidate->load([
            'office',
            'sponsor',
            'member.openingPositions',
            'interviews.recorder',
            'observationDays.recorder',
            'communications',
        ]));
    }

    public function updateCandidate(Request $request, KamsCandidate $candidate): JsonResponse
    {
        $this->assertCompany($request, (int) $candidate->company_id);

        $data = $request->validate([
            'company_id' => ['sometimes', 'exists:companies,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'full_name' => ['sometimes', 'string', 'max:255'],
            'gender' => ['nullable', 'in:male,female'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email'],
            'photo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'address' => ['nullable', 'string'],
            'guardian_name' => ['nullable', 'string'],
            'guardian_phone' => ['nullable', 'string'],
            'guardian_member_id' => ['nullable', 'exists:kams_members,id'],
            'source' => ['sometimes', 'in:poster,qr_code,online_form,sponsorship,social_media,other'],
            'sponsor_name' => ['nullable', 'string'],
            'sponsor_member_id' => ['nullable', 'exists:kams_members,id'],
            'notes' => ['nullable', 'string'],
            'documents' => ['nullable', 'array'],
        ]);

        $companyId = (int) ($data['company_id'] ?? $candidate->company_id);
        $this->assertCompany($request, $companyId);
        $this->assertOfficeCompany($data['office_id'] ?? $candidate->office_id, $companyId);
        unset($data['photo']);
        $data = $this->hydrateLinkedPeople($data);

        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
            $data = array_merge($data, PersonName::fromParts(
                $data['first_name'] ?? $candidate->first_name,
                $data['last_name'] ?? $candidate->last_name,
            ));
        } elseif (isset($data['full_name'])) {
            $data = array_merge($data, PersonName::fromFullName($data['full_name']));
        }

        $photoPath = $this->storePeoplePhoto($request);
        if ($photoPath) {
            $data['photo_path'] = $photoPath;
        } elseif ($request->boolean('remove_photo')) {
            $data['photo_path'] = null;
        }

        $old = $candidate->only(array_keys($data));
        $candidate->fill($data)->save();
        AuditLogger::record('update', $candidate, $old, $data);

        return $this->ok($candidate->fresh(['office', 'sponsor', 'guardian']));
    }

    public function destroyCandidate(Request $request, KamsCandidate $candidate): JsonResponse
    {
        $this->assertCompany($request, (int) $candidate->company_id);
        abort_if(
            $candidate->member_id || in_array($candidate->status, ['approved', 'registered'], true),
            422,
            'This application is already registered as a member.',
        );

        $candidate->delete();
        AuditLogger::record('delete', $candidate);

        return $this->ok(null, __('Deleted.'));
    }

    public function storeInterview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candidate_id' => ['required', 'exists:kams_candidates,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'interview_starts_at' => ['required', 'date'],
            'interviewer_user_id' => ['required', 'exists:users,id'],
            'attended' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'abandonment_reason' => ['required_if:result,abandoned', 'nullable', 'string'],
            'result' => ['required', 'in:admitted,rejected,on_hold,abandoned'],
        ]);

        $candidate = KamsCandidate::query()->findOrFail($data['candidate_id']);
        $this->assertCompany($request, (int) $candidate->company_id);
        abort_unless(in_array($candidate->status, ['received', 'under_review', 'shortlisted', 'on_hold'], true), 422, 'Interview is not available for this application.');

        $interviewer = User::query()->where('is_active', true)->findOrFail($data['interviewer_user_id']);
        $started = Carbon::parse($data['interview_starts_at']);
        $ended = now();
        if ($ended->lt($started)) {
            $ended = $started->copy();
        }

        $observationStarts = null;
        $observationEnds = null;
        if ($data['result'] === 'admitted') {
            $observationStarts = $started->copy()->setTime(7, 30);
            $observationEnds = $started->copy()->setTime(17, 30);
        }

        $interview = KamsInterview::query()->create([
            'company_id' => $candidate->company_id,
            'office_id' => $data['office_id'] ?? $candidate->office_id,
            'candidate_id' => $candidate->id,
            'interview_date' => $started->toDateString(),
            'interview_starts_at' => $started,
            'interview_ends_at' => $ended,
            'observation_starts_at' => $observationStarts,
            'observation_ends_at' => $observationEnds,
            'interviewer_user_id' => $interviewer->id,
            'interviewer_name' => $interviewer->name,
            'attended' => $data['attended'] ?? false,
            'notes' => $data['notes'] ?? null,
            'abandonment_reason' => $data['abandonment_reason'] ?? null,
            'result' => $data['result'],
            'recorded_by' => $request->user()->id,
            'recorded_at' => now(),
        ]);

        $status = match ($data['result']) {
            'admitted' => 'observation',
            'rejected' => 'rejected',
            'abandoned' => 'abandoned',
            default => 'on_hold',
        };
        $candidate->forceFill(['status' => $status])->save();

        if ($data['result'] === 'admitted') {
            $this->communications->notifyInterviewPassed($candidate, $request->getPreferredLanguage() === 'en' ? 'en' : 'fr');
        }

        AuditLogger::record('create', $interview, [], $interview->toArray());

        return $this->created($interview->load(['candidate', 'office', 'recorder', 'interviewer']));
    }

    public function storeObservation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'candidate_id' => ['required', 'exists:kams_candidates,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'observation_date' => ['required', 'date'],
            'attended' => ['nullable', 'boolean'],
            'supervisor_member_id' => ['required', 'exists:kams_members,id'],
            'supervisor_name' => ['nullable', 'string', 'max:255'],
            'evaluation' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'decision' => ['required', 'in:approved,rejected,on_hold'],
            'rejection_reason' => ['required_if:decision,rejected', 'nullable', 'string'],
        ]);

        $candidate = KamsCandidate::query()->findOrFail($data['candidate_id']);
        $this->assertCompany($request, (int) $candidate->company_id);
        abort_unless(in_array($candidate->status, ['observation', 'interview_admitted'], true), 422, 'Observation day is not available for this application.');

        $supervisor = KamsMember::query()->find($data['supervisor_member_id']);
        abort_unless($supervisor && (int) $supervisor->company_id === (int) $candidate->company_id, 422, 'The selected supervisor does not belong to this company.');
        $data['supervisor_name'] = $supervisor->full_name;

        $observation = KamsObservationDay::query()->create([
            ...$data,
            'company_id' => $candidate->company_id,
            'office_id' => $data['office_id'] ?? $candidate->office_id,
            'attended' => $data['attended'] ?? false,
            'recorded_by' => $request->user()->id,
            'recorded_at' => now(),
        ]);

        $candidate->forceFill([
            'status' => match ($data['decision']) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                default => 'on_hold',
            },
        ])->save();

        $member = null;
        if ($data['decision'] === 'approved') {
            $member = $this->registration->registerFromObservation(
                $observation,
                $request->getPreferredLanguage() === 'en' ? 'en' : 'fr'
            );
        }

        AuditLogger::record('create', $observation, [], $observation->toArray());

        return $this->created([
            'observation' => $observation->load(['candidate', 'office', 'recorder']),
            'member' => $member?->load(['office', 'openingPositions', 'user', 'role']),
        ]);
    }

    public function members(Request $request): JsonResponse
    {
        $query = $this->scopedMembers($request)
            ->with(['office', 'role', 'trainer', 'candidate', 'openingPositions', 'user'])
            ->latest();

        if ($origin = $request->string('origin')->toString()) {
            $query->where('origin', $origin);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('member_code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('office', function (Builder $office) use ($search) {
                        $office->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function storeMember(Request $request): JsonResponse
    {
        $request->merge([
            'trainer_user_id' => $request->filled('trainer_user_id') ? $request->input('trainer_user_id') : null,
            'role_id' => $request->filled('role_id') ? $request->input('role_id') : null,
        ]);

        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'gender' => ['required', 'in:male,female'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email'],
            'photo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'address' => ['nullable', 'string'],
            'trainer_name' => ['nullable', 'string', 'max:255'],
            'trainer_user_id' => ['nullable', 'exists:users,id'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_member_id' => ['nullable', 'exists:kams_members,id'],
            'assistant_manager_member_id' => ['nullable', 'exists:kams_members,id'],
            'fixed_salary' => ['nullable', 'numeric', 'min:0'],
            'rank' => ['nullable', 'string', 'max:64'],
            'role_id' => ['nullable', 'exists:roles,id'],
            'joined_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->assertCompany($request, (int) $data['company_id']);
        $this->assertOfficeCompany($data['office_id'] ?? null, (int) $data['company_id']);
        $photoPath = $this->storePeoplePhoto($request);
        unset($data['photo']);
        $data = array_merge($data, PersonName::fromParts($data['first_name'], $data['last_name']));
        $data = $this->withTrainerUser($data);

        $member = KamsMember::query()->create([
            ...$data,
            'member_code' => $this->identity->nextMemberCode((int) $data['company_id']),
            'photo_path' => $photoPath,
            'rank' => $data['rank'] ?? 'Distributor',
            'origin' => 'migrated',
            'status' => 'active',
            'joined_on' => $data['joined_on'] ?? now()->toDateString(),
            'migrated_at' => now(),
        ]);

        $this->accounts->provision($member);
        $this->wallets->ensureWallet($member);

        AuditLogger::record('create', $member, [], $member->toArray());

        return $this->created($member->fresh(['office', 'role', 'trainer', 'user', 'managerMember', 'assistantManager']));
    }

    public function showMember(Request $request, KamsMember $member): JsonResponse
    {
        $this->assertCompany($request, (int) $member->company_id);
        $this->accounts->provision($member);
        $this->wallets->ensureWallet($member);

        return $this->ok($member->fresh([
            'office',
            'role',
            'trainer',
            'managerMember',
            'assistantManager',
            'user',
            'candidate',
            'history.changer',
            'history.approver',
            'openingPositions',
            'communications',
        ]));
    }

    public function updateMember(Request $request, KamsMember $member): JsonResponse
    {
        $this->assertCompany($request, (int) $member->company_id);
        $request->merge([
            'trainer_user_id' => $request->exists('trainer_user_id') && ! $request->filled('trainer_user_id')
                ? null
                : $request->input('trainer_user_id'),
            'role_id' => $request->exists('role_id') && ! $request->filled('role_id')
                ? null
                : $request->input('role_id'),
        ]);

        $data = $request->validate([
            'office_id' => ['nullable', 'exists:offices,id'],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'gender' => ['nullable', 'in:male,female'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'photo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'remove_photo' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string'],
            'trainer_name' => ['nullable', 'string'],
            'trainer_user_id' => ['nullable', 'exists:users,id'],
            'manager_name' => ['nullable', 'string'],
            'manager_member_id' => ['nullable', 'exists:kams_members,id'],
            'assistant_manager_member_id' => ['nullable', 'exists:kams_members,id'],
            'fixed_salary' => ['nullable', 'numeric', 'min:0'],
            'team_name' => ['nullable', 'string'],
            'rank' => ['nullable', 'string'],
            'role_id' => ['nullable', 'exists:roles,id'],
            'joined_on' => ['nullable', 'date'],
            'status' => ['nullable', 'in:pending_verification,verified,active,inactive,disputed'],
            'reason' => ['nullable', 'string'],
        ]);

        $reason = $data['reason'] ?? 'Profile update';
        unset($data['reason'], $data['photo'], $data['remove_photo']);

        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
            $data = array_merge($data, PersonName::fromParts(
                $data['first_name'] ?? $member->first_name,
                $data['last_name'] ?? $member->last_name,
            ));
        }

        $data = $this->withTrainerUser($data, array_key_exists('trainer_user_id', $data));

        $photoPath = $this->storePeoplePhoto($request);
        if ($photoPath) {
            $data['photo_path'] = $photoPath;
        } elseif ($request->boolean('remove_photo')) {
            $data['photo_path'] = null;
        }

        $sensitive = in_array($member->status, ['verified', 'active'], true);

        foreach ($data as $field => $value) {
            $this->registration->applyProfileChange($member, $field, $value, $reason, $sensitive);
        }

        $member = $member->fresh(['office', 'role', 'trainer', 'managerMember', 'assistantManager', 'history', 'openingPositions', 'user']);
        $this->accounts->syncFromMember($member);

        return $this->ok($member->fresh(['office', 'role', 'trainer', 'managerMember', 'assistantManager', 'history', 'openingPositions', 'user']));
    }

    public function destroyMember(Request $request, KamsMember $member): JsonResponse
    {
        $this->assertCompany($request, (int) $member->company_id);
        $this->accounts->deactivate($member);
        $member->delete();
        AuditLogger::record('delete', $member);

        return $this->ok(null, __('Deleted.'));
    }

    public function setMemberAccount(Request $request, KamsMember $member): JsonResponse
    {
        $this->assertCompany($request, (int) $member->company_id);
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $this->accounts->setActive($member, (bool) $data['is_active']);

        return $this->ok($member->fresh(['office', 'role', 'trainer', 'user']));
    }

    public function completeFirstWeek(Request $request, KamsMember $member): JsonResponse
    {
        $this->assertCompany($request, (int) $member->company_id);
        abort_unless($member->origin === 'new_recruit', 422, 'First-week follow-up applies to new recruits only.');

        $member->forceFill([
            'first_week_completed' => true,
            'first_week_completed_at' => now(),
        ])->save();

        $this->communications->notifyFirstWeekCompleted($member, $request->getPreferredLanguage() === 'en' ? 'en' : 'fr');

        return $this->ok($member->fresh('communications'));
    }

    public function storeOpeningPosition(Request $request, KamsMember $member): JsonResponse
    {
        $this->assertCompany($request, (int) $member->company_id);

        $data = $request->validate([
            'category' => ['required', 'string', 'max:64'],
            'target_module' => ['nullable', 'string', 'max:64'],
            'amount' => ['required', 'numeric'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string'],
        ]);

        $position = KamsMemberOpeningPosition::query()->create([
            ...$data,
            'company_id' => $member->company_id,
            'member_id' => $member->id,
            'is_opening_balance' => true,
            'verified' => false,
            'notes' => $data['notes'] ?? 'Migrated opening balance. Not a new transaction.',
        ]);

        AuditLogger::record('create', $position, [], $position->toArray());

        return $this->created($position);
    }

    public function imports(Request $request): JsonResponse
    {
        $query = KamsMemberImport::query()->with(['importer', 'rows.member'])->latest();
        $companyIds = $request->user()->accessibleCompanyIds();

        if ($request->filled('company_id')) {
            $this->assertCompany($request, (int) $request->company_id);
            $query->where('company_id', $request->company_id);
        } elseif (! $request->user()->isGroupAdmin()) {
            $query->whereIn('company_id', $companyIds ?: [0]);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 15)));
    }

    public function importMembers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'source' => ['nullable', 'in:registry,database,excel,google_sheets,other'],
            'source_label' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'max:10240'],
            'rows' => ['nullable', 'array'],
            'rows.*' => ['array'],
        ]);

        $this->assertCompany($request, (int) $data['company_id']);

        $rows = $data['rows'] ?? [];
        if ($request->hasFile('file')) {
            $rows = $this->migration->rowsFromUpload($request->file('file'));
        }

        abort_if($rows === [], 422, __('Add an Excel/CSV file or paste rows to import.'));

        $import = $this->migration->import(
            (int) $data['company_id'],
            (int) $request->user()->id,
            $data['source'] ?? 'excel',
            $data['source_label'] ?? null,
            $rows,
        );

        return $this->created($import);
    }

    public function verifyImportRow(Request $request, KamsMemberImportRow $row): JsonResponse
    {
        $this->assertCompany($request, (int) $row->import()->value('company_id'));

        return $this->ok($this->migration->verifyRow($row, (int) $request->user()->id));
    }

    public function activateImportRow(Request $request, KamsMemberImportRow $row): JsonResponse
    {
        $this->assertCompany($request, (int) $row->import()->value('company_id'));

        return $this->ok($this->migration->activateRow($row));
    }

    public function communications(Request $request): JsonResponse
    {
        $query = KamsCommunication::query()->latest();
        $companyIds = $request->user()->accessibleCompanyIds();

        if ($request->filled('company_id')) {
            $this->assertCompany($request, (int) $request->company_id);
            $query->where('company_id', $request->company_id);
        } elseif (! $request->user()->isGroupAdmin()) {
            $query->whereIn('company_id', $companyIds ?: [0]);
        }

        if ($category = $request->string('category')->toString()) {
            $query->where('category', $category);
        }

        return $this->paginated($query->paginate($request->integer('per_page', 30)));
    }

    public function publicApply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'gender' => ['required', 'in:male,female'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email'],
            'photo' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'address' => ['nullable', 'string'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:64'],
            'source' => ['required', 'in:poster,qr_code,online_form,sponsorship,social_media,other'],
            'sponsor_name' => ['nullable', 'string', 'max:255'],
        ]);

        $photoPath = $this->storePeoplePhoto($request);
        unset($data['photo']);
        $data = array_merge($data, PersonName::fromParts($data['first_name'], $data['last_name']));

        $candidate = KamsCandidate::query()->create([
            ...$data,
            'candidate_id' => $this->identity->nextCandidateId((int) $data['company_id']),
            'status' => 'received',
            'applied_at' => now(),
            'source' => $data['source'],
            'photo_path' => $photoPath,
        ]);

        $this->communications->notifyApplicationReceived($candidate);
        AuditLogger::record('create', $candidate, [], $candidate->toArray());

        return $this->created([
            'candidate_id' => $candidate->candidate_id,
            'status' => $candidate->status,
        ]);
    }

    private function scopedCandidates(Request $request): Builder
    {
        $query = KamsCandidate::query();
        $this->applyCompanyScope($query, $request);
        $this->applyOfficeScope($query, $request);

        if ($search = $request->string('search')->toString()) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('candidate_id', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('office', function (Builder $office) use ($search) {
                        $office->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    private function scopedMembers(Request $request): Builder
    {
        $query = KamsMember::query();
        $this->applyMemberScope($query, $request);

        return $query;
    }

    private function applyMemberScope(Builder $query, Request $request): Builder
    {
        $this->applyCompanyScope($query, $request);
        $this->applyOfficeScope($query, $request);

        return $query;
    }

    private function applyCompanyScope(Builder $query, Request $request): void
    {
        $companyIds = $request->user()->accessibleCompanyIds();

        if ($request->filled('company_id')) {
            $this->assertCompany($request, (int) $request->company_id);
            $query->where('company_id', $request->company_id);
        } elseif (! $request->user()->isGroupAdmin()) {
            $query->whereIn('company_id', $companyIds ?: [0]);
        }
    }

    private function applyOfficeScope(Builder $query, Request $request): void
    {
        if ($request->filled('office_id')) {
            abort_unless($request->user()?->canAccessOffice((int) $request->office_id) ?? true, 403);
            $query->where('office_id', $request->integer('office_id'));

            return;
        }

        if ($request->user()->isGroupAdmin()) {
            return;
        }

        $officeIds = $request->user()->accessibleOfficeIds();

        if ($request->user()->isOfficeScoped()) {
            $query->whereIn('office_id', $officeIds ?: [0]);

            return;
        }

        $scoped = $request->user()->scopes
            ->where('scope_type', 'office')
            ->pluck('scope_id')
            ->filter()
            ->all();

        if ($scoped) {
            $query->whereIn('office_id', $scoped);
        }
    }

    private function assertCompany(Request $request, int $companyId): void
    {
        abort_unless($request->user()?->canAccessCompany($companyId) ?? true, 403);
    }

    private function assertOfficeCompany(mixed $officeId, int $companyId): void
    {
        if (! $officeId) {
            return;
        }

        $officeCompany = Office::query()->whereKey($officeId)->value('company_id');
        abort_unless((int) $officeCompany === $companyId, 422, 'The selected office does not belong to this company.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withTrainerUser(array $data, bool $fromPayload = true): array
    {
        if (! $fromPayload) {
            return $data;
        }

        $trainerId = $data['trainer_user_id'] ?? null;
        if (! $trainerId) {
            $data['trainer_user_id'] = null;
            $data['trainer_name'] = null;

            return $data;
        }

        $data['trainer_name'] = User::query()->whereKey($trainerId)->value('name');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function hydrateLinkedPeople(array $data): array
    {
        if (! empty($data['guardian_member_id'])) {
            $guardian = KamsMember::query()->find($data['guardian_member_id']);
            if ($guardian) {
                $data['guardian_name'] = $guardian->full_name;
                $data['guardian_phone'] = $guardian->phone;
            }
        }

        if (! empty($data['sponsor_member_id'])) {
            $sponsor = KamsMember::query()->find($data['sponsor_member_id']);
            if ($sponsor) {
                $data['sponsor_name'] = $sponsor->full_name;
            }
        }

        return $data;
    }

    private function storePeoplePhoto(Request $request): ?string
    {
        $photo = $request->file('photo');

        return $photo?->store('kams/photos', 'public');
    }
}
