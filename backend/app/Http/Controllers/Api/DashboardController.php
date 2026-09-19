<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\ModuleManager;
use App\Core\Support\ModuleOfficePerformance;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Office;
use App\Models\SystemModule;
use App\Models\Task;
use App\Models\User;
use App\Modules\Kams\Models\KamsSavingsAccount;
use App\Modules\Kams\Services\RevenueShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use RespondsWithJson;

    public function __invoke(Request $request, ModuleManager $modules, RevenueShareService $revenueShares): JsonResponse
    {
        $user = $request->user();
        $companyIds = $user->accessibleCompanyIds();
        $canSeeFinance = $user->hasPermission('finance.view') || $user->hasPermission('administration.view');
        $canSeeKamsCompensation = $canSeeFinance
            || $user->hasPermission('kams.finance.dashboard.view');

        $companies = Company::query()->whereIn('id', $companyIds ?: [0]);
        $finances = $canSeeFinance
            ? $modules->consolidateFinances($user->isGroupAdmin() ? null : $companyIds)
            : null;

        $performance = Company::query()
            ->whereIn('id', $companyIds ?: [0])
            ->withCount(['offices'])
            ->get()
            ->map(function (Company $company) use ($modules, $canSeeFinance) {
                $finance = $canSeeFinance ? $modules->consolidateFinances([$company->id]) : null;

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'code' => $company->code,
                    'offices_count' => $company->offices_count,
                    'revenue' => $finance['revenue'] ?? 0,
                    'profit' => $finance['profit'] ?? 0,
                ];
            });

        $kamsCompensation = null;
        $kamsSavings = null;
        if ($canSeeKamsCompensation) {
            $kamsCompanyIds = Company::query()
                ->whereIn('id', $companyIds ?: [0])
                ->whereHas('module', fn ($q) => $q->where('slug', 'kams'))
                ->pluck('id')
                ->all();
            $officeIds = ! $user->isGroupAdmin() && $user->isOfficeScoped()
                ? ($user->accessibleOfficeIds() ?: [0])
                : [];
            $from = now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->subWeeks(7)->toDateString();
            $to = now()->toDateString();
            $totals = [
                'generated' => 0.0,
                'posted' => 0.0,
                'remaining' => 0.0,
                'manager_sale' => 0.0,
                'assistant_share' => 0.0,
                'manager_office' => 0.0,
                'divisionnaire_office' => 0.0,
                'secretary_office' => 0.0,
                'salary_paid' => 0.0,
            ];
            foreach ($kamsCompanyIds as $companyId) {
                $row = $revenueShares->dashboardTotals((int) $companyId, $officeIds, $from, $to);
                $totals['generated'] += (float) $row['generated'];
                $totals['posted'] += (float) $row['posted'];
                $totals['remaining'] += (float) $row['remaining'];
                $totals['manager_sale'] += (float) $row['manager_sale'];
                $totals['assistant_share'] += (float) $row['assistant_share'];
                $totals['manager_office'] += (float) (($row['manager_office'] ?? 0) + ($row['manager_descendant_office'] ?? 0));
                $totals['divisionnaire_office'] += (float) $row['divisionnaire_office'];
                $totals['secretary_office'] += (float) $row['secretary_office'];
                $totals['salary_paid'] += (float) $row['salary_paid'];
            }
            $kamsCompensation = array_map(fn ($value) => round((float) $value, 2), $totals);
            $kamsCompensation['range'] = ['from' => $from, 'to' => $to];

            $savingsQuery = KamsSavingsAccount::query()
                ->whereIn('company_id', $kamsCompanyIds ?: [0])
                ->when($officeIds !== [], fn ($q) => $q->whereHas('member', fn ($m) => $m->whereIn('office_id', $officeIds)));
            $kamsSavings = [
                'guy_savings' => round((float) (clone $savingsQuery)->where('kind', 'guy')->sum('balance'), 2),
                'assistant_manager_savings' => round((float) (clone $savingsQuery)->where('kind', 'assistant_manager')->sum('balance'), 2),
                'manager_savings' => round((float) (clone $savingsQuery)->where('kind', 'manager')->sum('balance'), 2),
            ];
        }

        return $this->ok([
            'welcome' => [
                'name' => $user->name,
                'role' => $user->roles->first()?->name,
                'company' => $user->primaryCompany?->name,
            ],
            'stats' => [
                'countries' => Country::query()->count(),
                'cities' => City::query()->count(),
                'companies' => (clone $companies)->count(),
                'offices' => Office::query()->whereIn('company_id', $companyIds ?: [0])->count(),
                'users' => User::query()->count(),
                'active_modules' => SystemModule::query()->where('status', 'active')->count(),
            ],
            'finances' => $finances,
            'kams_compensation' => $kamsCompensation,
            'kams_savings' => $kamsSavings,
            'modules' => SystemModule::query()->orderBy('sort_order')->get(),
            'notifications' => AppNotification::query()
                ->where('user_id', $user->id)
                ->latest()
                ->limit(6)
                ->get(),
            'tasks' => Task::query()
                ->where('user_id', $user->id)
                ->where('status', '!=', 'completed')
                ->orderBy('due_date')
                ->limit(6)
                ->get(),
            'activity' => AuditLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(8)
                ->get(),
            'performance' => $performance,
            'presence' => $this->presence(),
            'trend' => $this->trend($finances['revenue'] ?? 0),
        ]);
    }

    public function modulePerformance(Request $request, ModuleOfficePerformance $performance): JsonResponse
    {
        $slug = $request->string('module')->toString() ?: null;

        return $this->ok($performance->build(
            $request->user(),
            $request->string('group_by')->toString() ?: 'week',
            $slug,
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ));
    }

    private function presence(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->withCount(['companies', 'offices'])
            ->orderBy('name')
            ->get()
            ->map(fn (Country $country) => [
                'id' => $country->id,
                'name' => $country->name,
                'name_fr' => $country->name_fr,
                'iso_code' => $country->iso_code,
                'latitude' => $country->latitude,
                'longitude' => $country->longitude,
                'companies_count' => $country->companies_count,
                'offices_count' => $country->offices_count,
            ])
            ->values()
            ->all();
    }

    private function trend(float $revenue): array
    {
        $points = [];
        for ($i = 5; $i >= 0; $i--) {
            $factor = 0.72 + (($i + 1) * 0.05);
            $points[] = [
                'month' => now()->subMonths($i)->translatedFormat('M'),
                'revenue' => round($revenue * $factor / 6, 2),
                'expenses' => round($revenue * $factor * 0.38 / 6, 2),
            ];
        }

        return $points;
    }
}
