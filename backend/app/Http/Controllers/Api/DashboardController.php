<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\GroupDashboardBuilder;
use App\Core\Support\ModuleOfficePerformance;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use RespondsWithJson;

    public function __invoke(Request $request, GroupDashboardBuilder $dashboard): JsonResponse
    {
        $companyId = $request->filled('company_id') ? (int) $request->integer('company_id') : null;
        $officeId = $request->filled('office_id') ? (int) $request->integer('office_id') : null;

        return $this->ok($dashboard->build(
            $request->user(),
            $request->string('group_by')->toString() ?: 'week',
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
            $companyId,
            $officeId,
        ));
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
}
