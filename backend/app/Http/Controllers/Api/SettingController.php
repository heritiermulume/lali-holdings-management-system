<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Mail\MailTestMail;
use App\Models\Setting;
use App\Support\MailSettings;
use App\Support\SettingsCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SettingController extends Controller
{
    use RespondsWithJson;

    public function bootstrap(): JsonResponse
    {
        SettingsCatalog::ensure();

        return $this->ok([
            'default_locale' => SettingsCatalog::defaultLocale(),
            'group_name' => SettingsCatalog::string('general', 'group_name'),
            'legal_name' => SettingsCatalog::string('general', 'legal_name'),
            'support_email' => SettingsCatalog::string('general', 'support_email'),
            'features' => (object) [],
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
            $meta = SettingsCatalog::meta($setting['group'], $setting['key']);
            Setting::query()->updateOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                [
                    'value' => is_array($setting['value']) ? json_encode($setting['value']) : $setting['value'],
                    'type' => $setting['type'] ?? $meta['type'] ?? 'string',
                ]
            );
        }

        SettingsCatalog::ensure();
        MailSettings::apply();

        return $this->ok(Setting::query()->orderBy('group')->orderBy('key')->get()->groupBy('group'), __('Settings saved.'));
    }

    public function testMail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['nullable', 'email'],
        ]);

        MailSettings::apply();

        $to = $data['to']
            ?? $request->user()?->email
            ?? SettingsCatalog::string('general', 'support_email');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'success' => false,
                'message' => __('No valid recipient email is available.'),
                'data' => null,
            ], 422);
        }

        $locale = $request->user()?->locale === 'en' ? 'en' : SettingsCatalog::defaultLocale();

        try {
            Mail::to($to)->send(new MailTestMail($locale));
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => __('Unable to send the test email: :message', [
                    'message' => $exception->getMessage(),
                ]),
                'data' => null,
            ], 422);
        }

        return $this->ok(['to' => $to], __('Test email sent to :email.', ['email' => $to]));
    }
}
