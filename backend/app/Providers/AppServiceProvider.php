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
use App\Models\Setting;
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
            try {
                $min = max(6, min(128, (int) Setting::getValue('security', 'password_min_length', 10)));
                $rule = Password::min($min);
                if ((bool) Setting::getValue('security', 'password_require_mixed', true)) {
                    $rule = $rule->mixedCase()->numbers()->symbols();
                }

                return $rule;
            } catch (\Throwable) {
                return Password::min(10);
            }
        });

        try {
            $minutes = (int) Setting::getValue('security', 'session_lifetime_minutes', 120);
            if ($minutes > 0) {
                config(['sanctum.expiration' => $minutes]);
            }
        } catch (\Throwable) {
            // Settings are unavailable before the database is migrated.
        }

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
