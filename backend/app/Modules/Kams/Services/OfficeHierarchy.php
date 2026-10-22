<?php

namespace App\Modules\Kams\Services;

use App\Models\Office;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsOfficeHistory;
use App\Modules\Kams\Models\KamsStockSale;
use Illuminate\Support\Collection;

class OfficeHierarchy
{
    /**
     * @return list<int>
     */
    public function ancestorIds(Office $office): array
    {
        $ids = [];
        $current = $office->parent_office_id ? (int) $office->parent_office_id : null;
        $guard = 0;
        while ($current && $guard++ < 40) {
            if (in_array($current, $ids, true)) {
                break;
            }
            $ids[] = $current;
            $current = Office::query()->whereKey($current)->value('parent_office_id');
            $current = $current ? (int) $current : null;
        }

        return $ids;
    }

    public function wouldCycle(Office $office, ?int $parentId): bool
    {
        if (! $parentId) {
            return false;
        }
        if ((int) $parentId === (int) $office->id) {
            return true;
        }

        return in_array((int) $office->id, $this->ancestorIds(new Office(['parent_office_id' => $parentId])), true)
            || in_array((int) $parentId, $this->descendantIds($office), true);
    }

    /**
     * @return list<int>
     */
    public function descendantIds(Office $office): array
    {
        return Office::descendantIdsFor([(int) $office->id]);
    }

    public function memberForUser(?int $userId, int $companyId): ?KamsMember
    {
        if (! $userId) {
            return null;
        }

        return KamsMember::query()
            ->with('role:id,name,slug')
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->first();
    }

    public function roleSlug(?KamsMember $member): ?string
    {
        if (! $member) {
            return null;
        }
        $slug = $member->role?->slug;
        if ($slug) {
            return $slug;
        }
        $rank = mb_strtolower((string) $member->rank);

        return match (true) {
            str_contains($rank, 'divisionnaire') => 'divisionnaire',
            str_contains($rank, 'vice') && str_contains($rank, 'président') => 'vice_president',
            str_contains($rank, 'vice') && str_contains($rank, 'president') => 'vice_president',
            str_contains($rank, 'partner') || str_contains($rank, 'partenaire') => 'partner',
            str_contains($rank, 'assistant') => 'assistant_manager',
            str_contains($rank, 'secrétaire') || str_contains($rank, 'secretary') => 'secretary',
            str_contains($rank, 'manager') => 'manager',
            default => null,
        };
    }

    /**
     * Roles that receive the local office manager package (product share + opened-office %).
     */
    public function isOfficeManagerRole(?string $slug): bool
    {
        return in_array($slug, ['manager', 'vice_president', 'partner'], true);
    }

    /**
     * The person chosen as office manager joins that bureau's agents.
     * A higher grade is kept. They stop being manager of any other bureau.
     */
    public function assignKamsManager(Office $office): void
    {
        $office->loadMissing('company.module');
        if ($office->company?->module?->slug !== 'kams' || ! $office->manager_user_id) {
            return;
        }

        $manager = \App\Models\User::query()->find($office->manager_user_id);
        if ($manager?->hasFullAccess()) {
            return;
        }

        $member = KamsMember::query()
            ->with('role:id,slug')
            ->where('company_id', $office->company_id)
            ->where('user_id', $office->manager_user_id)
            ->first();

        if ($member) {
            $member->office_id = $office->id;
            $slug = $this->roleSlug($member);
            $kept = in_array($slug, ['manager', 'divisionnaire', 'vice_president', 'partner'], true);
            if (! $kept) {
                $roleId = \App\Models\Role::query()
                    ->where('module_slug', 'kams')
                    ->where('slug', 'manager')
                    ->value('id');
                if ($roleId) {
                    $member->role_id = $roleId;
                    $member->rank = 'Manager';
                }
            }
            $member->save();
            app(\App\Modules\Kams\Services\AgentAccountService::class)->syncFromMember($member->fresh('role'));
        }

        Office::query()
            ->where('company_id', $office->company_id)
            ->where('manager_user_id', $office->manager_user_id)
            ->whereKeyNot($office->id)
            ->update(['manager_user_id' => null]);

        $user = \App\Models\User::query()->find($office->manager_user_id);
        if (! $user) {
            return;
        }

        $allowed = $user->ownedOfficeIds();
        if ($allowed === []) {
            $allowed = [(int) $office->id];
        }

        $user->forceFill([
            'primary_office_id' => in_array((int) $office->id, $allowed, true) ? $office->id : $allowed[0],
        ])->save();

        $user->scopes()->where('scope_type', 'office')->whereNotIn('scope_id', $allowed)->delete();
        foreach ($allowed as $officeId) {
            $user->scopes()->firstOrCreate([
                'scope_type' => 'office',
                'scope_id' => $officeId,
            ]);
        }

        $user->roles()->newPivotQuery()
            ->where('user_id', $user->id)
            ->whereNotNull('office_id')
            ->whereNotIn('office_id', $allowed)
            ->update(['office_id' => $user->primary_office_id]);
    }

    public function resolveAssistant(KamsMember $seller): ?KamsMember
    {
        // A member who is already an assistant manager leaves their former AM's direct team
        // for base remuneration — their own production is not included there.
        if ($this->roleSlug($seller) === 'assistant_manager') {
            return null;
        }

        $assistant = $seller->assistant_manager_member_id
            ? KamsMember::query()->with('role:id,name,slug')->find($seller->assistant_manager_member_id)
            : $this->linkedAssistantManager($seller);

        // A manager paid on their own office production is not part of an encadreur's
        // team when that encadreur sits in another office. The opener's percentage
        // already applies to the whole office.
        if ($assistant && $this->excludedFromRemoteEncadreurTeam($seller, $assistant)) {
            return null;
        }

        return $assistant;
    }

    /**
     * Upline assistant manager that "owns" this assistant (former team link).
     * Used for certified AM surplus: 1/8 of each sub-AM's team production.
     */
    public function resolveAssistantSponsor(KamsMember $assistant): ?KamsMember
    {
        $sponsor = null;
        if ($assistant->assistant_manager_member_id) {
            $linked = KamsMember::query()->with('role:id,name,slug')->find($assistant->assistant_manager_member_id);
            if ($linked && $this->roleSlug($linked) === 'assistant_manager') {
                $sponsor = $linked;
            }
        }
        if (! $sponsor) {
            $sponsor = $this->linkedAssistantManager($assistant);
        }
        if (! $sponsor || (int) $sponsor->id === (int) $assistant->id) {
            return null;
        }

        return $sponsor;
    }

    /**
     * Office leaders are remunerated on their bureau. They stay out of a remote encadreur's team.
     */
    public function excludedFromRemoteEncadreurTeam(KamsMember $member, KamsMember $encadreur): bool
    {
        $slug = $this->roleSlug($member);
        $paidOnOffice = $slug === 'divisionnaire' || $this->isOfficeManagerRole($slug);
        if (! $paidOnOffice || ! $member->office_id || ! $encadreur->office_id) {
            return false;
        }

        return (int) $member->office_id !== (int) $encadreur->office_id;
    }

    private function linkedAssistantManager(KamsMember $member): ?KamsMember
    {
        // Encadreur is the parrain / upline used by finance.
        if ($member->coach_member_id) {
            $coach = KamsMember::query()->with('role:id,name,slug')->find($member->coach_member_id);
            if ($coach && $this->roleSlug($coach) === 'assistant_manager') {
                return $coach;
            }
        }
        if ($member->trainer_user_id) {
            $trainer = $this->memberForUser((int) $member->trainer_user_id, (int) $member->company_id);
            if ($trainer && $this->roleSlug($trainer) === 'assistant_manager') {
                return $trainer;
            }
        }

        return null;
    }

    /**
     * Secretaries attached to an office.
     *
     * @return Collection<int, KamsMember>
     */
    public function secretariesForDirectOffice(Office $office): Collection
    {
        return KamsMember::query()
            ->with('role:id,name,slug')
            ->where('company_id', $office->company_id)
            ->where('office_id', $office->id)
            ->where(function ($q) {
                $q->whereHas('role', fn ($role) => $role->where('slug', 'secretary'))
                    ->orWhere('rank', 'like', '%Secretary%')
                    ->orWhere('rank', 'like', '%Secrétaire%')
                    ->orWhere('rank', 'like', '%Secretaire%');
            })
            ->get();
    }

    /**
     * Secretary is paid only on a direct child office of her manager's office, never on grandchildren.
     *
     * @return Collection<int, KamsMember>
     */
    public function secretariesOfParentOffice(Office $office): Collection
    {
        if (! $office->parent_office_id) {
            return collect();
        }
        $parent = Office::query()->find($office->parent_office_id);
        if (! $parent) {
            return collect();
        }

        return $this->secretariesForDirectOffice($parent);
    }

    /**
     * People who opened each ancestor office, from the parent upward.
     *
     * @return list<KamsMember>
     */
    public function ancestorOpeners(Office $office): array
    {
        $openers = [];
        $current = $office->parent_office_id ? Office::query()->find($office->parent_office_id) : null;
        $guard = 0;
        while ($current && $guard++ < 20) {
            $userId = $current->creator_user_id ?: $current->manager_user_id;
            $opener = $this->memberForUser($userId ? (int) $userId : null, (int) $current->company_id);
            if ($opener) {
                $openers[] = $opener;
            }
            $current = $current->parent_office_id ? Office::query()->find($current->parent_office_id) : null;
        }

        return $openers;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotForSale(KamsStockSale $sale): array
    {
        $sale->loadMissing(['member.role', 'member.office', 'manager.role']);
        $seller = $sale->member;
        $office = $seller?->office ?: ($seller?->office_id ? Office::query()->find($seller->office_id) : null);
        $office?->loadMissing(['parent:id,name,code', 'creator:id,name', 'manager:id,name']);

        $officeManager = $office
            ? ($this->memberForUser($office->manager_user_id ? (int) $office->manager_user_id : null, (int) $office->company_id) ?: $sale->manager)
            : $sale->manager;
        $officeCreator = $office
            ? $this->memberForUser($office->creator_user_id ? (int) $office->creator_user_id : null, (int) $office->company_id)
            : null;
        $assistant = $seller ? $this->resolveAssistant($seller) : null;
        $certifiedSponsor = $assistant ? $this->resolveAssistantSponsor($assistant) : null;
        $chain = $office ? $this->beneficiaryChain($office, $officeCreator) : ['managers' => [], 'divisionnaires' => []];
        $secretaries = $office ? $this->secretariesOfParentOffice($office) : collect();

        return [
            'seller' => $this->memberPayload($seller),
            'assistant_manager' => $this->memberPayload($assistant),
            'certified_assistant_sponsor' => $this->memberPayload($certifiedSponsor),
            'office' => $office ? [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
                'parent_office_id' => $office->parent_office_id,
                'parent' => $office->parent?->only(['id', 'name', 'code']),
                'creator_user_id' => $office->creator_user_id,
                'manager_user_id' => $office->manager_user_id,
            ] : null,
            'office_creator' => $this->memberPayload($officeCreator),
            'office_manager' => $this->memberPayload($officeManager),
            'upline_managers' => array_map(fn (array $row) => [
                'direct' => $row['direct'],
                'member' => $this->memberPayload($row['member']),
            ], $chain['managers']),
            'divisionnaires' => array_map(fn (KamsMember $member) => $this->memberPayload($member), $chain['divisionnaires']),
            'secretaries' => $secretaries->map(fn (KamsMember $member) => $this->memberPayload($member))->values()->all(),
            'assistant' => $assistant,
            'certified_assistant_sponsor_model' => $certifiedSponsor,
            'manager' => $officeManager,
            'creator' => $officeCreator,
            'office_model' => $office,
            'secretary_models' => $secretaries,
            'manager_models' => $chain['managers'],
            'divisionnaire_models' => $chain['divisionnaires'],
        ];
    }

    /**
     * @return array{managers: list<array{member: KamsMember, direct: bool}>, divisionnaires: list<KamsMember>}
     */
    public function beneficiaryChain(Office $office, ?KamsMember $creator): array
    {
        $managers = [];
        $divisionnaires = [];
        $seenMembers = [];

        $push = function (?KamsMember $member, bool $direct) use (&$managers, &$divisionnaires, &$seenMembers) {
            if (! $member || isset($seenMembers[$member->id])) {
                return;
            }
            $seenMembers[$member->id] = true;
            $slug = $this->roleSlug($member);
            if ($slug === 'divisionnaire') {
                $divisionnaires[] = $member;
            }
            // VP / partner share the same opened-office remuneration path as managers.
            if ($this->isOfficeManagerRole($slug)) {
                $managers[] = ['member' => $member, 'direct' => $direct];
            }
        };

        $push($creator, true);
        $officeManager = $this->memberForUser($office->manager_user_id ? (int) $office->manager_user_id : null, (int) $office->company_id);
        $push($officeManager, $creator?->id === $officeManager?->id);

        $walk = $creator ?: $officeManager;
        $guard = 0;
        while ($walk && $guard++ < 20) {
            $upline = $walk->manager_member_id
                ? KamsMember::query()->with('role:id,name,slug')->find($walk->manager_member_id)
                : null;
            // Encadreur is the parrain / upline used by finance.
            if (! $upline && $walk->coach_member_id) {
                $upline = KamsMember::query()->with('role:id,name,slug')->find($walk->coach_member_id);
            }
            if (! $upline && $walk->trainer_user_id) {
                $upline = $this->memberForUser((int) $walk->trainer_user_id, (int) $walk->company_id);
            }
            if (! $upline || $this->excludedFromRemoteEncadreurTeam($walk, $upline)) {
                break;
            }
            $push($upline, false);
            $walk = $upline;
        }

        $current = $office->parent_office_id ? Office::query()->find($office->parent_office_id) : null;
        $guard = 0;
        while ($current && $guard++ < 20) {
            $parentCreator = $this->memberForUser($current->creator_user_id ? (int) $current->creator_user_id : null, (int) $current->company_id);
            $parentManager = $this->memberForUser($current->manager_user_id ? (int) $current->manager_user_id : null, (int) $current->company_id);
            $push($parentCreator, false);
            $push($parentManager, false);
            $current = $current->parent_office_id ? Office::query()->find($current->parent_office_id) : null;
        }

        return ['managers' => $managers, 'divisionnaires' => $divisionnaires];
    }

    /**
     * @param  list<string>  $fields
     */
    public function recordHistory(Office $office, array $before, array $after, ?int $userId, array $fields = ['parent_office_id', 'creator_user_id', 'manager_user_id']): void
    {
        $snapshot = [
            'parent_office_id' => $office->parent_office_id,
            'creator_user_id' => $office->creator_user_id,
            'manager_user_id' => $office->manager_user_id,
            'recorded_by_user_id' => $office->recorded_by_user_id,
        ];
        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ((string) $old === (string) $new) {
                continue;
            }
            KamsOfficeHistory::query()->create([
                'company_id' => $office->company_id,
                'office_id' => $office->id,
                'changed_by' => $userId,
                'field' => $field,
                'old_value' => $old !== null ? (string) $old : null,
                'new_value' => $new !== null ? (string) $new : null,
                'snapshot' => $snapshot,
            ]);
        }
    }

    /**
     * @return array{id: int, full_name: string, member_code: string|null, role: string|null, user_id: int|null}|null
     */
    private function memberPayload(?KamsMember $member): ?array
    {
        if (! $member) {
            return null;
        }

        return [
            'id' => $member->id,
            'full_name' => $member->full_name,
            'member_code' => $member->member_code,
            'role' => $this->roleSlug($member),
            'user_id' => $member->user_id,
        ];
    }
}
