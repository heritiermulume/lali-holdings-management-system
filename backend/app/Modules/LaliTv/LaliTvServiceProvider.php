<?php

namespace App\Modules\LaliTv;

use Illuminate\Support\ServiceProvider;

class LaliTvServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LaliTvModule::class);
    }
}
