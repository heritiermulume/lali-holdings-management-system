<?php

namespace App\Providers;

use App\Core\Support\ModuleManager;
use App\Models\AppNotification;
use App\Models\SystemModule;
use App\Modules\Kams\Models\KamsCampaign;
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
use App\Support\SettingsCatalog;
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
        Password::defaults(function () {
            $min = max(6, min(128, (int) SettingsCatalog::number('security', 'password_min_length')));
            $rule = Password::min($min);
            if (SettingsCatalog::bool('security', 'password_require_mixed')) {
                $rule = $rule->mixedCase()->numbers()->symbols();
            }

            return $rule;
        });

        $minutes = (int) SettingsCatalog::number('security', 'session_lifetime_minutes');
        if ($minutes > 0) {
            config(['sanctum.expiration' => $minutes]);
        }

        MailSettings::apply();

        Route::model('module', SystemModule::class);
        Route::model('notification', AppNotification::class);
        Route::model('client', KamsClient::class);
        Route::model('depot', KamsDepot::class);
        Route::model('product', KamsProduct::class);
        Route::model('transfer', KamsStockTransfer::class);
        Route::model('assignment', KamsStockAssignment::class);
        Route::model('receipt', KamsStockReceipt::class);
        Route::model('sale', KamsStockSale::class);
        Route::model('stockReturn', KamsStockReturn::class);
        Route::model('commission', KamsSellerCommission::class);
        Route::model('order', KamsOrder::class);
        Route::model('campaign', KamsCampaign::class);
    }
}
