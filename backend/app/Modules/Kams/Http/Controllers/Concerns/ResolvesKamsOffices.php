<?php

namespace App\Modules\Kams\Http\Controllers\Concerns;

use App\Models\Company;
use App\Models\Office;
use Illuminate\Http\Request;

trait ResolvesKamsOffices
{
    private function kamsOffices(Request $request)
    {
        return Office::query()
            ->whereHas('company.module', fn ($q) => $q->where('slug', 'kams'))
            ->when(! $request->user()->isGroupAdmin(), function ($q) use ($request) {
                $q->whereIn('company_id', $request->user()->accessibleCompanyIds() ?: [0]);
                if ($request->user()->isOfficeScoped()) {
                    $q->whereIn('id', $request->user()->accessibleOfficeIds() ?: [0]);
                }
            });
    }

    private function canChooseOffice(Request $request, ?int $officeCount = null): bool
    {
        $count = $officeCount ?? $this->kamsOffices($request)->count();

        return $count > 1;
    }

    private function resolveClientOffice(Request $request, mixed $officeId): Office
    {
        $offices = $this->kamsOffices($request)->get();

        if ($this->canChooseOffice($request, $offices->count())) {
            abort_unless($officeId, 422, __('The office field is required.'));

            return $this->assertKamsOffice($request, (int) $officeId);
        }

        abort_unless($offices->count() === 1, 422, __('No office is available for this account.'));

        return $offices->first();
    }

    private function assertKamsOffice(Request $request, int $officeId): Office
    {
        $office = $this->kamsOffices($request)->whereKey($officeId)->first();
        abort_unless($office, 422, __('The selected office does not belong to this module.'));
        abort_unless($request->user()->canAccessOffice($officeId), 403);

        return $office;
    }

    private function assertCompany(Request $request, int $companyId): void
    {
        abort_unless($request->user()->canAccessCompany($companyId), 403);
    }

    private function kamsCompanyId(Request $request): ?int
    {
        if ($request->filled('company_id')) {
            $this->assertCompany($request, (int) $request->company_id);

            return (int) $request->company_id;
        }

        $companyId = $this->kamsOffices($request)->value('company_id');
        if (! $companyId) {
            // Company-level settings (e.g. revenue rules) must work before any office exists.
            $query = Company::query()->whereHas('module', fn ($q) => $q->where('slug', 'kams'));
            if (! $request->user()->isGroupAdmin()) {
                $query->whereIn('id', $request->user()->accessibleCompanyIds() ?: [0]);
            }
            $companyId = $query->value('id');
        }

        if ($companyId) {
            $this->assertCompany($request, (int) $companyId);
        }

        return $companyId ? (int) $companyId : null;
    }

    private function canManageWarehouses(Request $request): bool
    {
        return $request->user()->isGroupAdmin() || ! $request->user()->isOfficeScoped();
    }

    /**
     * Platform-wide office filter rules:
     * - 1 accessible office → selector hidden, all queries locked to that office
     * - several accessible offices → selector shown, optional office_id narrows the set
     * - multi-office company/group (not office-scoped) with no selection → no office filter
     *
     * @return array{
     *     offices: \Illuminate\Support\Collection<int, Office>,
     *     can_filter: bool,
     *     office_ids: list<int>,
     *     selected_office_id: int|null
     * }
     */
    private function resolveOfficeFilterState(Request $request, ?int $companyId = null): array
    {
        $offices = $this->kamsOffices($request)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $accessibleIds = $offices->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $canFilter = count($accessibleIds) > 1;
        $selected = null;

        if ($request->filled('office_id')) {
            $selected = (int) $request->integer('office_id');
            $this->assertKamsOffice($request, $selected);
            $officeIds = [$selected];
        } elseif (! $canFilter) {
            $officeIds = $accessibleIds;
            $selected = $accessibleIds[0] ?? null;
        } elseif ($request->user()->isOfficeScoped()) {
            $officeIds = $accessibleIds;
        } else {
            $officeIds = [];
        }

        return [
            'offices' => $offices,
            'can_filter' => $canFilter,
            'office_ids' => $officeIds,
            'selected_office_id' => $selected,
        ];
    }

    /**
     * @param  array{offices: \Illuminate\Support\Collection, can_filter: bool, office_ids: list<int>, selected_office_id: int|null}  $state
     * @return array{can_filter_offices: bool, office_id: int|null, filter_offices: list<array{id:int,name:string,code:?string}>}
     */
    private function officeFilterPayload(array $state): array
    {
        return [
            'can_filter_offices' => $state['can_filter'],
            'office_id' => $state['selected_office_id'],
            'filter_offices' => $state['offices']->map(fn (Office $office) => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ])->values()->all(),
        ];
    }

    /**
     * Apply resolved office ids to a query (column or relation constraint).
     * Empty office_ids = no extra filter (multi-office company/group view).
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  array{office_ids: list<int>}  $state
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     */
    private function applyOfficeIds($query, array $state, string $column = 'office_id')
    {
        if (($state['office_ids'] ?? []) !== []) {
            $query->whereIn($column, $state['office_ids']);
        }

        return $query;
    }
}
