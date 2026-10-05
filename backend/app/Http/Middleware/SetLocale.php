<?php

namespace App\Http\Middleware;

use App\Support\SettingsCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('Accept-Language')
            ?: $request->user()?->locale
            ?: SettingsCatalog::defaultLocale();
        $locale = strtolower(substr((string) $locale, 0, 2));

        $supported = config('lhms.supported_locales', ['fr', 'en']);
        if (! in_array($locale, $supported, true)) {
            $locale = SettingsCatalog::defaultLocale();
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
