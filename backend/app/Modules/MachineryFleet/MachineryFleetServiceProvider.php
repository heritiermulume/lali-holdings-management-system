<?php

namespace App\Modules\MachineryFleet;

use Illuminate\Support\ServiceProvider;

class MachineryFleetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MachineryFleetModule::class);
    }
}
