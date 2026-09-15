<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    use RespondsWithJson;

    public function bootstrap(): JsonResponse
    {
        SettingsCatalog::ensure();

        return $this->ok([
            'default_locale' => SettingsCatalog::defaultLocale(),
            'group_name' => Setting::getValue('general', 'group_name', 'LALI Holdings'),
        ]);
    }

    public function index(): JsonResponse
    {
        SettingsCatalog::ensure();

        return $this->ok(Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group'));
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

        SettingsCatalog::ensure();
        AuditLogger::record('update', null, [], $data);

        return $this->ok(Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group'), __('Settings saved.'));
    }
}
