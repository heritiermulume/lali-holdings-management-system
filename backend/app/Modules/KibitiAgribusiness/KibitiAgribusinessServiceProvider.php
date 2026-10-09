<?php

namespace App\Modules\KibitiAgribusiness;

use Illuminate\Support\ServiceProvider;

class KibitiAgribusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KibitiAgribusinessModule::class);
    }
}
