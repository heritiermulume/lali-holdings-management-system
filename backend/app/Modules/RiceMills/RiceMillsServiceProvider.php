<?php

namespace App\Modules\RiceMills;

use Illuminate\Support\ServiceProvider;

class RiceMillsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RiceMillsModule::class);
    }
}
