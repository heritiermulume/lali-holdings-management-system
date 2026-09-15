<?php

use App\Http\Middleware\EnsurePermission;
use App\Modules\Kams\Http\Controllers\KamsDashboardController;
use App\Modules\Kams\Http\Controllers\KamsRecruitmentController;
use App\Modules\Kams\Http\Controllers\KamsResourceController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', KamsDashboardController::class)->middleware(EnsurePermission::class.':kams.dashboard.view');

Route::get('clients', [KamsResourceController::class, 'clients'])->middleware(EnsurePermission::class.':kams.clients.view');
Route::post('clients', [KamsResourceController::class, 'storeClient'])->middleware(EnsurePermission::class.':kams.clients.create');
Route::put('clients/{client}', [KamsResourceController::class, 'updateClient'])->middleware(EnsurePermission::class.':kams.clients.update');
Route::delete('clients/{client}', [KamsResourceController::class, 'destroyClient'])->middleware(EnsurePermission::class.':kams.clients.delete');

Route::get('products', [KamsResourceController::class, 'products'])->middleware(EnsurePermission::class.':kams.products.view');
Route::post('products', [KamsResourceController::class, 'storeProduct'])->middleware(EnsurePermission::class.':kams.products.create');
Route::put('products/{product}', [KamsResourceController::class, 'updateProduct'])->middleware(EnsurePermission::class.':kams.products.update');
Route::delete('products/{product}', [KamsResourceController::class, 'destroyProduct'])->middleware(EnsurePermission::class.':kams.products.delete');

Route::get('orders', [KamsResourceController::class, 'orders'])->middleware(EnsurePermission::class.':kams.orders.view');
Route::post('orders', [KamsResourceController::class, 'storeOrder'])->middleware(EnsurePermission::class.':kams.orders.create');
Route::put('orders/{order}', [KamsResourceController::class, 'updateOrder'])->middleware(EnsurePermission::class.':kams.orders.update');

Route::get('campaigns', [KamsResourceController::class, 'campaigns'])->middleware(EnsurePermission::class.':kams.campaigns.view');
Route::post('campaigns', [KamsResourceController::class, 'storeCampaign'])->middleware(EnsurePermission::class.':kams.campaigns.create');
Route::put('campaigns/{campaign}', [KamsResourceController::class, 'updateCampaign'])->middleware(EnsurePermission::class.':kams.campaigns.update');
Route::delete('campaigns/{campaign}', [KamsResourceController::class, 'destroyCampaign'])->middleware(EnsurePermission::class.':kams.campaigns.delete');

Route::get('recruitment/lookups', [KamsRecruitmentController::class, 'lookups'])->middleware(EnsurePermission::class.':kams.members.view,kams.recruitment.view');
Route::get('recruitment/dashboard', [KamsRecruitmentController::class, 'dashboard'])->middleware(EnsurePermission::class.':kams.recruitment.view');
Route::get('recruitment/communications', [KamsRecruitmentController::class, 'communications'])->middleware(EnsurePermission::class.':kams.recruitment.view');

Route::get('candidates', [KamsRecruitmentController::class, 'candidates'])->middleware(EnsurePermission::class.':kams.recruitment.view');
Route::post('candidates', [KamsRecruitmentController::class, 'storeCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.create');
Route::get('candidates/{candidate}', [KamsRecruitmentController::class, 'showCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.view');
Route::match(['put', 'post'], 'candidates/{candidate}', [KamsRecruitmentController::class, 'updateCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.update');
Route::delete('candidates/{candidate}', [KamsRecruitmentController::class, 'destroyCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.delete');

Route::post('interviews', [KamsRecruitmentController::class, 'storeInterview'])->middleware(EnsurePermission::class.':kams.recruitment.create');
Route::post('observation-days', [KamsRecruitmentController::class, 'storeObservation'])->middleware(EnsurePermission::class.':kams.recruitment.create');

Route::get('members', [KamsRecruitmentController::class, 'members'])->middleware(EnsurePermission::class.':kams.members.view');
Route::post('members', [KamsRecruitmentController::class, 'storeMember'])->middleware(EnsurePermission::class.':kams.members.create');
Route::get('members/{member}', [KamsRecruitmentController::class, 'showMember'])->middleware(EnsurePermission::class.':kams.members.view');
Route::match(['put', 'post'], 'members/{member}', [KamsRecruitmentController::class, 'updateMember'])->middleware(EnsurePermission::class.':kams.members.update');
Route::post('members/{member}/account', [KamsRecruitmentController::class, 'setMemberAccount'])->middleware(EnsurePermission::class.':kams.members.activate');
Route::delete('members/{member}', [KamsRecruitmentController::class, 'destroyMember'])->middleware(EnsurePermission::class.':kams.members.delete');
Route::post('members/{member}/first-week', [KamsRecruitmentController::class, 'completeFirstWeek'])->middleware(EnsurePermission::class.':kams.members.update');
Route::post('members/{member}/opening-positions', [KamsRecruitmentController::class, 'storeOpeningPosition'])->middleware(EnsurePermission::class.':kams.members.create');

Route::get('member-imports', [KamsRecruitmentController::class, 'imports'])->middleware(EnsurePermission::class.':kams.members.view');
Route::post('member-imports', [KamsRecruitmentController::class, 'importMembers'])->middleware(EnsurePermission::class.':kams.members.import');
Route::post('member-import-rows/{row}/verify', [KamsRecruitmentController::class, 'verifyImportRow'])->middleware(EnsurePermission::class.':kams.members.update');
Route::post('member-import-rows/{row}/activate', [KamsRecruitmentController::class, 'activateImportRow'])->middleware(EnsurePermission::class.':kams.members.activate');
