<?php

use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Services\KamsAccountProvisioner;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $wallets = app(KamsAccountProvisioner::class);

        KamsMember::query()->each(fn (KamsMember $member) => $wallets->ensureWallet($member));

        User::query()->where('is_active', true)->with('roles')->each(function (User $user) use ($wallets) {
            $wallets->tryForUser($user);
        });
    }

    public function down(): void
    {
        //
    }
};
