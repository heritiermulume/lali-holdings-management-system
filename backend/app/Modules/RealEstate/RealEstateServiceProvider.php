<?php

namespace App\Modules\RealEstate;

use Illuminate\Support\ServiceProvider;

class RealEstateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RealEstateModule::class);
    }
}
