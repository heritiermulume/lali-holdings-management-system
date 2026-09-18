<?php

namespace App\Modules\Kams\Http\Controllers\Concerns;

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
        if ($request->user()->isGroupAdmin()) {
            return true;
        }

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
        if ($companyId) {
            $this->assertCompany($request, (int) $companyId);
        }

        return $companyId ? (int) $companyId : null;
    }

    private function canManageWarehouses(Request $request): bool
    {
        return $request->user()->isGroupAdmin() || ! $request->user()->isOfficeScoped();
    }
}
