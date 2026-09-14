<?php

use App\Http\Middleware\EnsurePermission;
use App\Modules\Kams\Http\Controllers\KamsDashboardController;
use App\Modules\Kams\Http\Controllers\KamsResourceController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', KamsDashboardController::class)->middleware(EnsurePermission::class.':kams.view');

Route::get('clients', [KamsResourceController::class, 'clients'])->middleware(EnsurePermission::class.':kams.view');
Route::post('clients', [KamsResourceController::class, 'storeClient'])->middleware(EnsurePermission::class.':kams.create');
Route::put('clients/{client}', [KamsResourceController::class, 'updateClient'])->middleware(EnsurePermission::class.':kams.update');
Route::delete('clients/{client}', [KamsResourceController::class, 'destroyClient'])->middleware(EnsurePermission::class.':kams.delete');

Route::get('products', [KamsResourceController::class, 'products'])->middleware(EnsurePermission::class.':kams.view');
Route::post('products', [KamsResourceController::class, 'storeProduct'])->middleware(EnsurePermission::class.':kams.create');
Route::put('products/{product}', [KamsResourceController::class, 'updateProduct'])->middleware(EnsurePermission::class.':kams.update');
Route::delete('products/{product}', [KamsResourceController::class, 'destroyProduct'])->middleware(EnsurePermission::class.':kams.delete');

Route::get('orders', [KamsResourceController::class, 'orders'])->middleware(EnsurePermission::class.':kams.view');
Route::post('orders', [KamsResourceController::class, 'storeOrder'])->middleware(EnsurePermission::class.':kams.create');
Route::put('orders/{order}', [KamsResourceController::class, 'updateOrder'])->middleware(EnsurePermission::class.':kams.update');

Route::get('campaigns', [KamsResourceController::class, 'campaigns'])->middleware(EnsurePermission::class.':kams.view');
Route::post('campaigns', [KamsResourceController::class, 'storeCampaign'])->middleware(EnsurePermission::class.':kams.create');
Route::put('campaigns/{campaign}', [KamsResourceController::class, 'updateCampaign'])->middleware(EnsurePermission::class.':kams.update');
Route::delete('campaigns/{campaign}', [KamsResourceController::class, 'destroyCampaign'])->middleware(EnsurePermission::class.':kams.delete');
