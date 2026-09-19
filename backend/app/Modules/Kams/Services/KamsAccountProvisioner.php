<?php

namespace App\Modules\Kams\Services;

use App\Models\Company;
use App\Models\Office;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use Illuminate\Support\Facades\DB;

class KamsAccountProvisioner
{
    public function __construct(
        private readonly SavingsLedger $ledger,
        private readonly KamsIdentityService $identity,
        private readonly TreasuryLedger $treasury,
    ) {}

    public function forUser(User $user, ?int $companyId = null): KamsMember
    {
        $member = $this->existingMember($user);

        if (! $member) {
            $companyId = $companyId ?: $this->kamsCompanyIdFor($user);
            abort_unless($companyId, 422, __('No KAMS company is available to open an account.'));

            $member = DB::transaction(function () use ($user, $companyId) {
                $existing = $this->existingMember($user);
                if ($existing) {
                    return $existing;
                }

                $role = $user->roles()->orderByDesc('roles.level')->first();

                return KamsMember::query()->create([
                    'company_id' => $companyId,
                    'office_id' => $this->officeIdFor($user, $companyId),
                    'user_id' => $user->id,
                    'member_code' => $this->identity->nextMemberCode($companyId),
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'full_name' => trim($user->name) !== '' ? $user->name : trim($user->first_name.' '.$user->last_name),
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role_id' => $role?->id,
                    'rank' => $role?->name,
                    'origin' => 'staff',
                    'status' => 'active',
                    'joined_on' => now()->toDateString(),
                ]);
            });
        }

        $this->ensureWallet($member);
        if ($member->company_id) {
            $this->ensureCompanyBooks((int) $member->company_id);
        }

        return $member->fresh(['office', 'role']) ?? $member;
    }

    private function existingMember(User $user): ?KamsMember
    {
        $member = KamsMember::withTrashed()->where('user_id', $user->id)->first()
            ?: KamsMember::withTrashed()
                ->where('email', $user->email)
                ->where(function ($q) use ($user) {
                    $q->whereNull('user_id')->orWhere('user_id', $user->id);
                })
                ->first();

        if (! $member) {
            return null;
        }

        if ($member->trashed()) {
            $member->restore();
        }

        if (! $member->user_id) {
            $member->forceFill([
                'user_id' => $user->id,
                'email' => $user->email,
            ])->save();
        }

        return $member;
    }

    public function tryForUser(User $user, ?int $companyId = null): ?KamsMember
    {
        $companyId = $companyId ?: $this->kamsCompanyIdFor($user);
        if (! $companyId && ! KamsMember::withTrashed()->where('user_id', $user->id)->exists()) {
            return null;
        }

        return $this->forUser($user, $companyId);
    }

    public function ensureWallet(KamsMember $member): void
    {
        $this->ledger->account($member, 'guy');
        if ($this->isManagerial($member)) {
            $this->ledger->account($member, 'manager');
        }
        $slug = $member->role?->slug ?: \App\Models\Role::query()->whereKey($member->role_id)->value('slug');
        if ($slug === 'assistant_manager') {
            $this->ledger->account($member, 'assistant_manager');
        }
        if ($slug === 'secretary') {
            $this->ledger->account($member, 'secretary');
        }
        if ($slug === 'divisionnaire') {
            $this->ledger->account($member, 'divisionnaire');
        }
        if ($member->company_id) {
            $this->ensureCompanyBooks((int) $member->company_id);
        }
    }

    public function ensureCompanyBooks(int $companyId): void
    {
        $this->treasury->ensureForCompany($companyId);
        Office::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->each(fn (Office $office) => $this->treasury->ensureOffice($office));
    }

    public function kamsCompanyIdFor(User $user): ?int
    {
        $query = Company::query()->whereHas('module', fn ($q) => $q->where('slug', 'kams'));
        if (! $user->isGroupAdmin()) {
            $query->whereIn('id', $user->accessibleCompanyIds() ?: [0]);
        }
        if ($user->primary_company_id) {
            $match = (clone $query)->whereKey($user->primary_company_id)->value('id');
            if ($match) {
                return (int) $match;
            }
        }

        $id = $query->value('id');

        return $id ? (int) $id : null;
    }

    private function officeIdFor(User $user, int $companyId): ?int
    {
        if ($user->primary_office_id) {
            $office = Office::query()
                ->whereKey($user->primary_office_id)
                ->where('company_id', $companyId)
                ->value('id');
            if ($office) {
                return (int) $office;
            }
        }

        $id = Office::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    private function isManagerial(KamsMember $member): bool
    {
        $slug = $member->role?->slug ?: Role::query()->whereKey($member->role_id)->value('slug');
        $rank = strtolower((string) $member->rank);

        return in_array($slug, ['manager', 'assistant_manager', 'secretary', 'divisionnaire', 'vice_president', 'partner', 'president', 'super_administrator'], true)
            || str_contains($rank, 'manager')
            || str_contains($rank, 'président')
            || str_contains($rank, 'president')
            || str_contains($rank, 'partner')
            || str_contains($rank, 'divisionnaire')
            || str_contains($rank, 'secrétaire')
            || str_contains($rank, 'secretary');
    }
}
