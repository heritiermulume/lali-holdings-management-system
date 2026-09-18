<?php

namespace App\Modules\Kams\Services;

use App\Core\Support\AuditLogger;
use App\Core\Support\RoleCatalog;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsMemberImport;
use App\Modules\Kams\Models\KamsMemberImportRow;
use App\Modules\Kams\Models\KamsMemberOpeningPosition;
use App\Modules\Kams\Support\PersonName;
use App\Modules\Kams\Support\SpreadsheetRows;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemberMigrationService
{
    public function __construct(
        private readonly KamsIdentityService $identity,
        private readonly RecruitmentCommunicationService $communications,
        private readonly AgentAccountService $accounts,
        private readonly KamsAccountProvisioner $wallets,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function import(int $companyId, int $userId, string $source, ?string $sourceLabel, array $rows): KamsMemberImport
    {
        return DB::transaction(function () use ($companyId, $userId, $source, $sourceLabel, $rows) {
            $import = KamsMemberImport::query()->create([
                'company_id' => $companyId,
                'imported_by' => $userId,
                'source' => $source,
                'source_label' => $sourceLabel,
                'status' => 'pending_verification',
                'row_count' => 0,
                'issue_count' => 0,
                'imported_at' => now(),
            ]);

            $issueCount = 0;

            foreach ($rows as $index => $payload) {
                $normalized = $this->resolveLinkedRecords($this->normalizeRow($payload));
                $issues = $this->detectIssues($companyId, $normalized);
                $member = $this->createPendingMember($companyId, $normalized, $issues);
                $this->accounts->provision($member);
                $member = $member->fresh(['user']);

                KamsMemberImportRow::query()->create([
                    'import_id' => $import->id,
                    'member_id' => $member->id,
                    'line_number' => $index + 1,
                    'payload' => $normalized,
                    'issues' => $issues,
                    'status' => $issues === [] ? 'pending_verification' : 'imported',
                ]);

                $this->storeOpeningPositions($companyId, $member, $normalized);
                $this->communications->notifyMigration($member, $issues === [] ? 'profile_verification' : 'missing_information', 'fr', implode(', ', $issues));

                if ($issues !== []) {
                    $issueCount++;
                }
            }

            $import->forceFill([
                'row_count' => count($rows),
                'issue_count' => $issueCount,
            ])->save();

            AuditLogger::record('import_members', $import, [], [
                'source' => $source,
                'rows' => count($rows),
                'issues' => $issueCount,
            ]);

            return $import->load('rows.member.user');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rowsFromUpload(UploadedFile $file): array
    {
        return SpreadsheetRows::fromUpload($file);
    }

    public function verifyRow(KamsMemberImportRow $row, int $userId): KamsMemberImportRow
    {
        $member = $row->member;
        abort_unless($member, 422, 'Import row has no member profile.');

        $member->forceFill(['status' => 'verified'])->save();
        $row->forceFill([
            'status' => 'verified',
            'verified_by' => $userId,
            'verified_at' => now(),
        ])->save();

        $member->openingPositions()->update(['verified' => true]);

        AuditLogger::record('verify_member', $member);

        return $row->fresh(['member.openingPositions']);
    }

    public function activateRow(KamsMemberImportRow $row): KamsMember
    {
        $member = $row->member;
        abort_unless($member, 422, 'Import row has no member profile.');

        if ($member->status === 'active' && $row->status === 'active') {
            return $member->fresh(['openingPositions', 'office']);
        }

        abort_unless($row->status === 'verified' || $member->status === 'verified', 422, 'Member must be verified before activation.');

        $member->forceFill(['status' => 'active'])->save();
        $row->forceFill(['status' => 'active'])->save();

        $this->communications->notifyMigration($member, 'migration_confirmation');
        AuditLogger::record('activate_member', $member);

        $import = $row->import;
        if ($import && $import->rows()->whereNotIn('status', ['active', 'rejected'])->doesntExist()) {
            $import->forceFill(['status' => 'completed'])->save();
        }

        return $member->fresh(['openingPositions', 'office']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeRow(array $payload): array
    {
        $map = [
            'member_code' => ['member_code', 'id_membre', 'member id', 'id'],
            'full_name' => ['full_name', 'nom', 'name', 'nom_complet'],
            'first_name' => ['first_name', 'prenom', 'prénom', 'firstname'],
            'last_name' => ['last_name', 'nom_famille', 'lastname', 'family_name'],
            'phone' => ['phone', 'telephone', 'tel'],
            'address' => ['address', 'adresse'],
            'guardian_name' => ['guardian_name', 'parent', 'tuteur'],
            'guardian_phone' => ['guardian_phone', 'contact_parent'],
            'trainer_name' => ['trainer_name', 'formateur', 'trainer'],
            'trainer_email' => ['trainer_email', 'formateur_email', 'encadreur_email'],
            'trainer_user_id' => ['trainer_user_id', 'trainer_id', 'formateur_id'],
            'manager_name' => ['manager_name', 'manager'],
            'office_code' => ['office_code', 'bureau', 'office', 'location'],
            'joined_on' => ['joined_on', 'date_adhesion', 'join_date'],
            'rank' => ['rank', 'rang'],
            'role' => ['role', 'role_slug', 'role_name'],
            'gender' => ['gender', 'sexe'],
            'email' => ['email', 'e_mail', 'mail'],
            'status' => ['status', 'statut'],
            'team_name' => ['team_name', 'equipe', 'team'],
            'consignment_balance' => ['consignment_balance', 'solde_consignation'],
            'amounts_due' => ['amounts_due', 'sommes_dues'],
            'distributor_savings' => ['distributor_savings', 'epargne_distributeur'],
            'manager_savings' => ['manager_savings', 'epargne_manager'],
        ];

        $normalized = [];
        $lower = [];
        foreach ($payload as $key => $value) {
            $lower[Str::of((string) $key)->lower()->replace(['-', ' '], '_')->toString()] = $value;
        }

        foreach ($map as $field => $aliases) {
            foreach ($aliases as $alias) {
                $alias = Str::of($alias)->lower()->replace(['-', ' '], '_')->toString();
                if (array_key_exists($alias, $lower) && $lower[$alias] !== '' && $lower[$alias] !== null) {
                    $normalized[$field] = is_string($lower[$alias]) ? trim($lower[$alias]) : $lower[$alias];
                    break;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resolveLinkedRecords(array $row): array
    {
        $trainerId = $row['trainer_user_id'] ?? null;
        $trainerEmail = $row['trainer_email'] ?? null;

        if (filled($trainerId) || filled($trainerEmail)) {
            $trainer = User::query()
                ->when(filled($trainerId), fn ($q) => $q->whereKey($trainerId))
                ->when(blank($trainerId) && filled($trainerEmail), fn ($q) => $q->where('email', $trainerEmail))
                ->first();

            if ($trainer) {
                $row['trainer_user_id'] = $trainer->id;
                $row['trainer_name'] = $trainer->name;
            } else {
                $row['trainer_unresolved'] = true;
            }
        }

        if (filled($row['role'] ?? null)) {
            $roleValue = (string) $row['role'];
            $slug = RoleCatalog::resolve($roleValue);
            $role = $slug
                ? Role::query()->where('slug', $slug)->first()
                : Role::query()
                    ->where(function ($query) use ($roleValue) {
                        $query->where('slug', $roleValue)->orWhere('name', $roleValue);
                    })
                    ->first();

            if ($role) {
                $row['role_id'] = $role->id;
            } else {
                $row['role_unresolved'] = true;
            }
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function detectIssues(int $companyId, array $row): array
    {
        $issues = [];

        if (blank($row['full_name'] ?? null)) {
            $issues[] = 'missing_information';
        }

        if (filled($row['member_code'] ?? null)) {
            $exists = KamsMember::query()
                ->where('company_id', $companyId)
                ->where('member_code', $row['member_code'])
                ->exists();
            if ($exists) {
                $issues[] = 'duplicate_member_id';
            }
        }

        if (filled($row['phone'] ?? null) && filled($row['full_name'] ?? null)) {
            $duplicate = KamsMember::query()
                ->where('company_id', $companyId)
                ->where('phone', $row['phone'])
                ->where('full_name', $row['full_name'])
                ->exists();
            if ($duplicate) {
                $issues[] = 'duplicate_member';
            }
        }

        if (filled($row['office_code'] ?? null)) {
            $office = Office::query()->where('code', $row['office_code'])->where('company_id', $companyId)->first();
            if (! $office) {
                $issues[] = 'incorrect_office';
            }
        }

        if (! empty($row['trainer_unresolved']) || (blank($row['trainer_name'] ?? null) && blank($row['trainer_user_id'] ?? null))) {
            $issues[] = 'incorrect_trainer';
        }

        if (! empty($row['role_unresolved'])) {
            $issues[] = 'incorrect_role';
        }

        if (strtolower((string) ($row['status'] ?? '')) === 'inactive') {
            $issues[] = 'inactive_member';
        }

        if (($row['disputed'] ?? false) === true || strtolower((string) ($row['status'] ?? '')) === 'disputed') {
            $issues[] = 'disputed_information';
        }

        $hasOpening = collect(['consignment_balance', 'amounts_due', 'distributor_savings', 'manager_savings'])
            ->contains(fn ($key) => isset($row[$key]) && $row[$key] !== '' && $row[$key] !== null);

        if ($hasOpening) {
            $issues[] = 'unverified_opening_balance';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $issues
     */
    private function createPendingMember(int $companyId, array $row, array $issues): KamsMember
    {
        $officeId = null;
        if (filled($row['office_code'] ?? null)) {
            $officeId = Office::query()->where('company_id', $companyId)->where('code', $row['office_code'])->value('id');
        }

        if (filled($row['member_code'] ?? null)) {
            $existing = KamsMember::query()
                ->where('company_id', $companyId)
                ->where('member_code', $row['member_code'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $status = in_array('disputed_information', $issues, true)
            ? 'disputed'
            : (in_array('inactive_member', $issues, true) ? 'inactive' : 'pending_verification');

        $code = filled($row['member_code'] ?? null)
            ? (string) $row['member_code']
            : $this->identity->nextMemberCode($companyId);

        $names = PersonName::fromParts($row['first_name'] ?? null, $row['last_name'] ?? null);
        if ($names['full_name'] === '') {
            $names = PersonName::fromFullName($row['full_name'] ?? 'Unknown');
        }

        return tap(KamsMember::query()->create([
            'company_id' => $companyId,
            'office_id' => $officeId,
            'member_code' => $code,
            ...$names,
            'gender' => $row['gender'] ?? null,
            'phone' => $row['phone'] ?? null,
            'email' => $row['email'] ?? null,
            'address' => $row['address'] ?? null,
            'guardian_name' => $row['guardian_name'] ?? null,
            'guardian_phone' => $row['guardian_phone'] ?? null,
            'trainer_name' => $row['trainer_name'] ?? null,
            'trainer_user_id' => $row['trainer_user_id'] ?? null,
            'manager_name' => $row['manager_name'] ?? null,
            'team_name' => $row['team_name'] ?? null,
            'rank' => $row['rank'] ?? null,
            'role_id' => $row['role_id'] ?? null,
            'origin' => 'migrated',
            'status' => $status,
            'joined_on' => $row['joined_on'] ?? null,
            'migrated_at' => now(),
            'notes' => 'Migrated existing member. Not a new recruit.',
        ]), fn (KamsMember $member) => $this->wallets->ensureWallet($member));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function storeOpeningPositions(int $companyId, KamsMember $member, array $row): void
    {
        $map = [
            'consignment_balance' => ['consignment', 'consignment'],
            'amounts_due' => ['amounts_due', 'settlement'],
            'distributor_savings' => ['distributor_savings', 'savings'],
            'manager_savings' => ['manager_savings', 'savings'],
        ];

        foreach ($map as $field => [$category, $module]) {
            if (! isset($row[$field]) || $row[$field] === '' || $row[$field] === null) {
                continue;
            }

            KamsMemberOpeningPosition::query()->create([
                'company_id' => $companyId,
                'member_id' => $member->id,
                'category' => $category,
                'target_module' => $module,
                'amount' => (float) $row[$field],
                'is_opening_balance' => true,
                'verified' => false,
                'notes' => 'Migrated opening balance. Not a new transaction.',
            ]);
        }
    }
}
