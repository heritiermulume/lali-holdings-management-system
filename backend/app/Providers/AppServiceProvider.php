<?php

namespace App\Providers;

use App\Core\Support\ModuleManager;
use App\Models\AppNotification;
use App\Models\SystemModule;
use App\Modules\Kams\Models\KamsCampaign;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsProduct;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);
    }

    public function boot(): void
    {
        Route::model('module', SystemModule::class);
        Route::model('notification', AppNotification::class);
        Route::model('client', KamsClient::class);
        Route::model('product', KamsProduct::class);
        Route::model('order', KamsOrder::class);
        Route::model('campaign', KamsCampaign::class);
    }
}
