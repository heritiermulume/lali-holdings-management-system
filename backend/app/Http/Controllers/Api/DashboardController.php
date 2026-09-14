<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\ModuleManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Office;
use App\Models\SystemModule;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use RespondsWithJson;

    public function __invoke(Request $request, ModuleManager $modules): JsonResponse
    {
        $user = $request->user();
        $companyIds = $user->accessibleCompanyIds();
        $canSeeFinance = $user->hasPermission('finance.view') || $user->hasPermission('dashboard.group');

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
            'trend' => $this->trend($finances['revenue'] ?? 0),
        ]);
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
