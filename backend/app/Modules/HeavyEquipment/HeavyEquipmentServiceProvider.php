<?php

namespace App\Modules\HeavyEquipment;

use Illuminate\Support\ServiceProvider;

class HeavyEquipmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HeavyEquipmentModule::class);
    }
}
