<?php

namespace App\Modules\AgribusinessParkKisegese;

use Illuminate\Support\ServiceProvider;

class AgribusinessParkKisegeseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgribusinessParkKisegeseModule::class);
    }
}
