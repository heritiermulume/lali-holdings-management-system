<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Core\Support\ModuleManager;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Models\Company;
use App\Models\SystemModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    use RespondsWithJson;

    public function index(ModuleManager $modules): JsonResponse
    {
        $instances = $modules->instances();

        return $this->ok(
            SystemModule::query()->orderBy('sort_order')->get()->map(function (SystemModule $module) use ($instances) {
                $instance = $instances[$module->slug] ?? null;
                $payload = $module->toArray();

                if ($instance) {
                    $names = $instance->name();
                    $descriptions = $instance->description();
                    $payload['name'] = $names['en'] ?? $payload['name'];
                    $payload['name_fr'] = $names['fr'] ?? $payload['name_fr'];
                    $payload['description'] = $descriptions['en'] ?? $payload['description'];
                    $payload['description_fr'] = $descriptions['fr'] ?? $payload['description_fr'];
                    $payload['available'] = $instance->isAvailable();
                }

                return $payload;
            })
        );
    }

    public function update(Request $request, SystemModule $module): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:active,in_development,coming_soon,inactive'],
        ]);

        $module->fill($data)->save();
        AuditLogger::record('update', $module, [], $data);

        return $this->ok($module->fresh());
    }

    public function syncCompany(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*.id' => ['required', 'exists:system_modules,id'],
            'modules.*.is_enabled' => ['required', 'boolean'],
        ]);

        $sync = [];
        foreach ($data['modules'] as $module) {
            $sync[$module['id']] = ['is_enabled' => $module['is_enabled']];
        }

        $company->modules()->sync($sync);
        AuditLogger::record('update', $company, [], $sync);

        return $this->ok($company->fresh('modules'));
    }
}
