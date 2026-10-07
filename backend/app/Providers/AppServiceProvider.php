<?php

namespace App\Providers;

use App\Core\Support\ModuleManager;
use App\Models\AppNotification;
use App\Models\SystemModule;
use App\Modules\Kams\Models\KamsAsset;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockReceipt;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockTransfer;
use App\Support\MailSettings;
use App\Support\SecuritySettings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleManager::class);
    }

    public function boot(): void
    {
        Password::defaults(fn () => SecuritySettings::passwordRule());
        SecuritySettings::apply();
        MailSettings::apply();

        Route::model('module', SystemModule::class);
        Route::model('notification', AppNotification::class);
        Route::model('client', KamsClient::class);
        Route::model('asset', KamsAsset::class);
        Route::model('depot', KamsDepot::class);
        Route::model('product', KamsProduct::class);
        Route::model('transfer', KamsStockTransfer::class);
        Route::model('assignment', KamsStockAssignment::class);
        Route::model('receipt', KamsStockReceipt::class);
        Route::model('sale', KamsStockSale::class);
        Route::model('stockReturn', KamsStockReturn::class);
        Route::model('commission', KamsSellerCommission::class);
        Route::model('order', KamsOrder::class);
    }
}
