<?php

namespace App\Modules\Kams;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class KamsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KamsModule::class);
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum'])
            ->prefix('api/v1/modules/kams')
            ->group(__DIR__.'/routes.php');
    }
}
