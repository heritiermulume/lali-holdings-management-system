<?php

namespace App\Modules\PropertiesMagomeni;

use Illuminate\Support\ServiceProvider;

class PropertiesMagomeniServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PropertiesMagomeniModule::class);
    }
}
