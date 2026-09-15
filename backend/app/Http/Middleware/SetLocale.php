<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?: $request->header('Accept-Language', 'fr');
        $locale = strtolower(substr((string) $locale, 0, 2));

        if (! in_array($locale, config('lhms.supported_locales', ['fr', 'en']), true)) {
            $locale = 'fr';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
