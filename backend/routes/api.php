<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsurePermission;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::get('public/bootstrap', [SettingController::class, 'bootstrap']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::match(['put', 'post'], 'auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/avatar', [AuthController::class, 'updateAvatar']);
        Route::delete('auth/avatar', [AuthController::class, 'destroyAvatar']);
        Route::put('auth/password', [AuthController::class, 'updatePassword']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        Route::get('dashboard', DashboardController::class);
        Route::get('dashboard/module-performance', [DashboardController::class, 'modulePerformance']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

        Route::get('tasks', [TaskController::class, 'index']);
        Route::post('tasks', [TaskController::class, 'store']);
        Route::put('tasks/{task}', [TaskController::class, 'update']);

        Route::get('modules', [ModuleController::class, 'index'])->middleware(EnsurePermission::class.':modules.view');
        Route::put('modules/{module}', [ModuleController::class, 'update'])->middleware(EnsurePermission::class.':modules.manage');
        Route::put('companies/{company}/modules', [ModuleController::class, 'syncCompany'])->middleware(EnsurePermission::class.':modules.manage');

        Route::get('users', [UserController::class, 'index'])->middleware(EnsurePermission::class.':users.view');
        Route::post('users', [UserController::class, 'store'])->middleware(EnsurePermission::class.':users.create');
        Route::post('users/import', [UserController::class, 'import'])->middleware(EnsurePermission::class.':users.import');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware(EnsurePermission::class.':users.view');
        Route::match(['put', 'post'], 'users/{user}', [UserController::class, 'update'])->middleware(EnsurePermission::class.':users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware(EnsurePermission::class.':users.delete');

        Route::get('roles', [RoleController::class, 'index'])->middleware(EnsurePermission::class.':roles.view');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware(EnsurePermission::class.':roles.view');
        Route::put('roles/{role}', [RoleController::class, 'update'])->middleware(EnsurePermission::class.':roles.manage');

        Route::get('organization/tree', [OrganizationController::class, 'tree'])->middleware(EnsurePermission::class.':organization.view');

        Route::get('countries', [OrganizationController::class, 'countries'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('countries', [OrganizationController::class, 'storeCountry'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('countries/{country}', [OrganizationController::class, 'updateCountry'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('countries/{country}', [OrganizationController::class, 'destroyCountry'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('provinces', [OrganizationController::class, 'provinces'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('provinces', [OrganizationController::class, 'storeProvince'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('provinces/{province}', [OrganizationController::class, 'updateProvince'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('provinces/{province}', [OrganizationController::class, 'destroyProvince'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('cities', [OrganizationController::class, 'cities'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('cities', [OrganizationController::class, 'storeCity'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('cities/{city}', [OrganizationController::class, 'updateCity'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('cities/{city}', [OrganizationController::class, 'destroyCity'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('communes', [OrganizationController::class, 'communes'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('communes', [OrganizationController::class, 'storeCommune'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('communes/{commune}', [OrganizationController::class, 'updateCommune'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('communes/{commune}', [OrganizationController::class, 'destroyCommune'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('neighborhoods', [OrganizationController::class, 'neighborhoods'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('neighborhoods', [OrganizationController::class, 'storeNeighborhood'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('neighborhoods/{neighborhood}', [OrganizationController::class, 'updateNeighborhood'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('neighborhoods/{neighborhood}', [OrganizationController::class, 'destroyNeighborhood'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('companies', [OrganizationController::class, 'companies'])->middleware(EnsurePermission::class.':companies.view');
        Route::post('companies', [OrganizationController::class, 'storeCompany'])->middleware(EnsurePermission::class.':companies.create');
        Route::get('companies/{company}', [OrganizationController::class, 'showCompany'])->middleware(EnsurePermission::class.':companies.view');
        Route::put('companies/{company}', [OrganizationController::class, 'updateCompany'])->middleware(EnsurePermission::class.':companies.update');
        Route::delete('companies/{company}', [OrganizationController::class, 'destroyCompany'])->middleware(EnsurePermission::class.':companies.delete');

        Route::get('offices/lookups', [OrganizationController::class, 'officeLookups'])->middleware(EnsurePermission::class.':organization.view,companies.view');
        Route::get('offices', [OrganizationController::class, 'offices'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('offices', [OrganizationController::class, 'storeOffice'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('offices/{office}', [OrganizationController::class, 'updateOffice'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('offices/{office}', [OrganizationController::class, 'destroyOffice'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('departments', [OrganizationController::class, 'departments'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('departments', [OrganizationController::class, 'storeDepartment'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('departments/{department}', [OrganizationController::class, 'updateDepartment'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('departments/{department}', [OrganizationController::class, 'destroyDepartment'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('activities', [OrganizationController::class, 'activities'])->middleware(EnsurePermission::class.':organization.view');
        Route::post('activities', [OrganizationController::class, 'storeActivity'])->middleware(EnsurePermission::class.':organization.create');
        Route::put('activities/{activity}', [OrganizationController::class, 'updateActivity'])->middleware(EnsurePermission::class.':organization.update');
        Route::delete('activities/{activity}', [OrganizationController::class, 'destroyActivity'])->middleware(EnsurePermission::class.':organization.delete');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware(EnsurePermission::class.':audit.view');
        Route::get('settings', [SettingController::class, 'index'])->middleware(EnsurePermission::class.':settings.manage');
        Route::put('settings', [SettingController::class, 'update'])->middleware(EnsurePermission::class.':settings.manage');

        Route::get('finance', [FinanceController::class, 'overview'])->middleware(EnsurePermission::class.':settings.manage');
        Route::get('finance/catalog', [FinanceController::class, 'catalog'])->middleware(EnsurePermission::class.':settings.manage');
        Route::post('finance/currencies', [FinanceController::class, 'storeCurrency'])->middleware(EnsurePermission::class.':settings.manage');
        Route::put('finance/currencies/{currency}', [FinanceController::class, 'updateCurrency'])->middleware(EnsurePermission::class.':settings.manage');
        Route::delete('finance/currencies/{currency}', [FinanceController::class, 'destroyCurrency'])->middleware(EnsurePermission::class.':settings.manage');
        Route::post('finance/sync-rates', [FinanceController::class, 'syncRates'])->middleware(EnsurePermission::class.':settings.manage');
        Route::get('finance/convert', [FinanceController::class, 'convert'])->middleware(EnsurePermission::class.':settings.manage');
    });
});
