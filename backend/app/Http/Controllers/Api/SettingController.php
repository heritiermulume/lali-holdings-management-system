<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    use RespondsWithJson;

    public function index(): JsonResponse
    {
        return $this->ok(Setting::query()->get()->groupBy('group'));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.group' => ['required', 'string'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['nullable'],
            'settings.*.type' => ['nullable', 'string'],
        ]);

        foreach ($data['settings'] as $setting) {
            Setting::query()->updateOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => is_array($setting['value']) ? json_encode($setting['value']) : $setting['value'], 'type' => $setting['type'] ?? 'string']
            );
        }

        AuditLogger::record('update', null, [], $data);

        return $this->ok(Setting::query()->get()->groupBy('group'), __('Settings saved.'));
    }
}
