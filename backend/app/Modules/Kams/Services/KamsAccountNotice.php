<?php

namespace App\Modules\Kams\Services;

use App\Mail\KamsAccountNoticeMail;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

class KamsAccountNotice
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(User $user, array $payload): void
    {
        if (! filled($user->email)) {
            return;
        }

        $payload['currency'] = $payload['currency'] ?? (Currency::defaultCode() ?: null);

        try {
            Mail::to($user->email)->send(new KamsAccountNoticeMail($user, $payload));
        } catch (Throwable) {
            // The ledger movement must succeed even if mail delivery fails.
        }
    }

    public function frontend(string $path): string
    {
        return rtrim((string) config('lhms.frontend_url'), '/').$path;
    }
}
