<?php

use App\Http\Middleware\EnsurePermission;
use App\Modules\Kams\Http\Controllers\KamsAssetController;
use App\Modules\Kams\Http\Controllers\KamsDashboardController;
use App\Modules\Kams\Http\Controllers\KamsFinanceController;
use App\Modules\Kams\Http\Controllers\KamsRecruitmentController;
use App\Modules\Kams\Http\Controllers\KamsResourceController;
use App\Modules\Kams\Http\Controllers\KamsRevenueController;
use App\Modules\Kams\Http\Controllers\KamsStockController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', KamsDashboardController::class)->middleware(EnsurePermission::class.':kams.dashboard.view');

Route::get('clients/lookups', [KamsResourceController::class, 'clientLookups'])->middleware(EnsurePermission::class.':kams.clients.view,kams.suppliers.view');
Route::get('clients', [KamsResourceController::class, 'clients'])->middleware(EnsurePermission::class.':kams.clients.view,kams.suppliers.view');
Route::post('clients', [KamsResourceController::class, 'storeClient'])->middleware(EnsurePermission::class.':kams.clients.create');
Route::put('clients/{client}', [KamsResourceController::class, 'updateClient'])->middleware(EnsurePermission::class.':kams.clients.update');
Route::delete('clients/{client}', [KamsResourceController::class, 'destroyClient'])->middleware(EnsurePermission::class.':kams.clients.delete');

Route::get('patrimoine/lookups', [KamsAssetController::class, 'lookups'])->middleware(EnsurePermission::class.':kams.patrimoine.view');
Route::get('patrimoine', [KamsAssetController::class, 'index'])->middleware(EnsurePermission::class.':kams.patrimoine.view');
Route::post('patrimoine', [KamsAssetController::class, 'store'])->middleware(EnsurePermission::class.':kams.patrimoine.create');
Route::match(['put', 'post'], 'patrimoine/{asset}', [KamsAssetController::class, 'update'])->middleware(EnsurePermission::class.':kams.patrimoine.update');
Route::delete('patrimoine/{asset}', [KamsAssetController::class, 'destroy'])->middleware(EnsurePermission::class.':kams.patrimoine.delete');

Route::get('stock/lookups', [KamsStockController::class, 'lookups'])->middleware(EnsurePermission::class.':kams.products.view,kams.stock.assignments.view,kams.orders.view,kams.stock.statistics.view,kams.stock.warehouses.view,kams.stock.depots.view,kams.stock.reports.view,kams.stock.journal.view,kams.stock.orders.view');
Route::get('stock/dashboard', [KamsStockController::class, 'dashboard'])->middleware(EnsurePermission::class.':kams.stock.statistics.view');
Route::get('stock/receipts', [KamsStockController::class, 'receipts'])->middleware(EnsurePermission::class.':kams.stock.warehouses.view,kams.stock.depots.view');
Route::post('stock/receipts', [KamsStockController::class, 'receive'])->middleware(EnsurePermission::class.':kams.stock.update,kams.stock.warehouses.receive,kams.stock.depots.receive');
Route::post('stock/receipts/{receipt}/validate', [KamsStockController::class, 'validateReceipt'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::post('stock/receipts/{receipt}/cancel', [KamsStockController::class, 'cancelReceipt'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::get('stock/transfers', [KamsStockController::class, 'transfers'])->middleware(EnsurePermission::class.':kams.stock.warehouses.view,kams.stock.depots.view');
Route::post('stock/transfers', [KamsStockController::class, 'storeTransfer'])->middleware(EnsurePermission::class.':kams.stock.update,kams.stock.warehouses.transfer,kams.stock.depots.supply');
Route::post('stock/transfers/{transfer}/receive', [KamsStockController::class, 'receiveTransfer'])->middleware(EnsurePermission::class.':kams.stock.update,kams.stock.depots.receive,kams.stock.warehouses.receive');
Route::post('stock/transfers/{transfer}/cancel', [KamsStockController::class, 'cancelTransfer'])->middleware(EnsurePermission::class.':kams.stock.update,kams.stock.warehouses.transfer');
Route::get('stock/assignments', [KamsStockController::class, 'assignments'])->middleware(EnsurePermission::class.':kams.stock.assignments.view');
Route::get('stock/assignments/report', [KamsStockController::class, 'assignmentsReport'])->middleware(EnsurePermission::class.':kams.stock.reports.view');
Route::delete('stock/assignments/report', [KamsStockController::class, 'destroyAssignmentsReport'])->middleware(EnsurePermission::class.':kams.stock.reports.delete');
Route::get('stock/journal', [KamsStockController::class, 'journal'])->middleware(EnsurePermission::class.':kams.stock.journal.view');
Route::delete('stock/journal/{entry}', [KamsStockController::class, 'destroyJournal'])->middleware(EnsurePermission::class.':kams.stock.journal.delete');
Route::post('stock/assignments', [KamsStockController::class, 'storeAssignment'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::post('stock/assignments/close-day', [KamsStockController::class, 'closeAssignmentDay'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::post('stock/assignments/{assignment}/close', [KamsStockController::class, 'closeAssignment'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::post('stock/assignments/{assignment}/items', [KamsStockController::class, 'addAssignmentItems'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::put('stock/assignments/{assignment}', [KamsStockController::class, 'updateAssignment'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::delete('stock/assignments/{assignment}', [KamsStockController::class, 'destroyAssignment'])->middleware(EnsurePermission::class.':kams.stock.assignments.delete');
Route::get('stock/sales', [KamsStockController::class, 'sales'])->middleware(EnsurePermission::class.':kams.stock.assignments.view,kams.orders.view');
Route::post('stock/sales/{sale}/pay', [KamsStockController::class, 'paySale'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::get('stock/returns', [KamsStockController::class, 'returns'])->middleware(EnsurePermission::class.':kams.stock.warehouses.view,kams.stock.depots.view,kams.stock.assignments.view');
Route::post('stock/returns', [KamsStockController::class, 'storeReturn'])->middleware(EnsurePermission::class.':kams.stock.update,kams.stock.depots.remove');
Route::post('stock/returns/{stockReturn}/accept', [KamsStockController::class, 'acceptReturn'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::get('stock/adjustments', [KamsStockController::class, 'adjustments'])->middleware(EnsurePermission::class.':kams.stock.warehouses.view,kams.stock.depots.view,kams.stock.assignments.view');
Route::post('stock/adjustments', [KamsStockController::class, 'storeAdjustment'])->middleware(EnsurePermission::class.':kams.stock.update,kams.stock.depots.adjust');
Route::get('stock/movements', [KamsStockController::class, 'movements'])->middleware(EnsurePermission::class.':kams.stock.warehouses.view,kams.stock.depots.view');
Route::get('stock/commissions', [KamsStockController::class, 'commissions'])->middleware(EnsurePermission::class.':kams.stock.assignments.view');
Route::post('stock/commissions/{commission}/pay', [KamsStockController::class, 'payCommission'])->middleware(EnsurePermission::class.':kams.stock.update');
Route::get('stock/hierarchy', [KamsStockController::class, 'hierarchy'])->middleware(EnsurePermission::class.':kams.stock.assignments.view,kams.orders.view');
Route::get('stock/savings', [KamsStockController::class, 'savings'])->middleware(EnsurePermission::class.':kams.stock.assignments.view');
Route::get('stock/daily-settlements', [KamsStockController::class, 'dailySettlement'])->middleware(EnsurePermission::class.':kams.stock.assignments.view,kams.finance.dashboard.view');
Route::post('stock/daily-settlements', [KamsStockController::class, 'closeDailySettlement'])->middleware(EnsurePermission::class.':kams.stock.update,kams.finance.company.expense');

Route::get('revenue/rules', [KamsRevenueController::class, 'rules'])->middleware(EnsurePermission::class.':kams.finance.dashboard.view,settings.manage');
Route::put('revenue/rules/{rule}', [KamsRevenueController::class, 'updateRule'])->middleware(EnsurePermission::class.':kams.finance.dashboard.update,settings.manage');
Route::get('revenue/summary', [KamsRevenueController::class, 'summary'])->middleware(EnsurePermission::class.':kams.finance.dashboard.view,kams.finance.account.view');
Route::get('revenue/sales/{sale}', [KamsRevenueController::class, 'sale'])->middleware(EnsurePermission::class.':kams.finance.dashboard.view,kams.orders.view');
Route::get('revenue/offices', [KamsRevenueController::class, 'offices'])->middleware(EnsurePermission::class.':kams.finance.dashboard.view,organization.view,settings.manage');
Route::get('revenue/offices/{office}/history', [KamsRevenueController::class, 'officeHistory'])->middleware(EnsurePermission::class.':kams.finance.dashboard.view,organization.view');
Route::post('revenue/members/{member}/salary', [KamsRevenueController::class, 'postSalary'])->middleware(EnsurePermission::class.':kams.finance.dashboard.update,kams.finance.company.expense');

Route::get('finance/dashboard', [KamsFinanceController::class, 'dashboard'])->middleware(EnsurePermission::class.':kams.finance.dashboard.view');
Route::get('finance/member-accounts', [KamsFinanceController::class, 'memberAccounts'])->middleware(EnsurePermission::class.':kams.finance.accounts.view,kams.finance.dashboard.view');
Route::get('finance/account', [KamsFinanceController::class, 'account'])->middleware(EnsurePermission::class.':kams.finance.account.view');
Route::post('finance/account/deposit', [KamsFinanceController::class, 'deposit'])->middleware(EnsurePermission::class.':kams.finance.account.deposit');
Route::post('finance/account/withdraw', [KamsFinanceController::class, 'withdraw'])->middleware(EnsurePermission::class.':kams.finance.account.withdraw');
Route::post('finance/account/post-deposit', [KamsFinanceController::class, 'postDeposit'])->middleware(EnsurePermission::class.':kams.finance.company.deposit,kams.finance.account.deposit');
Route::post('finance/account/post-withdraw', [KamsFinanceController::class, 'postWithdraw'])->middleware(EnsurePermission::class.':kams.finance.company.withdraw,kams.finance.account.withdraw');
Route::post('finance/account/entries/{savingsEntry}/approve', [KamsFinanceController::class, 'accountApprove'])->middleware(EnsurePermission::class.':kams.finance.company.approve');
Route::post('finance/account/entries/{savingsEntry}/reject', [KamsFinanceController::class, 'accountReject'])->middleware(EnsurePermission::class.':kams.finance.company.approve');
Route::post('finance/company-accounts/{treasuryAccount}/entries/{treasuryEntry}/approve', [KamsFinanceController::class, 'companyApprove'])->middleware(EnsurePermission::class.':kams.finance.company.approve');
Route::post('finance/company-accounts/{treasuryAccount}/entries/{treasuryEntry}/reject', [KamsFinanceController::class, 'companyReject'])->middleware(EnsurePermission::class.':kams.finance.company.approve');
Route::get('finance/company-accounts', [KamsFinanceController::class, 'companyAccounts'])->middleware(EnsurePermission::class.':kams.finance.company.view');
Route::get('finance/company-accounts/{treasuryAccount}', [KamsFinanceController::class, 'companyAccount'])->middleware(EnsurePermission::class.':kams.finance.company.view');
Route::get('finance/company-accounts/{treasuryAccount}/commissions', [KamsFinanceController::class, 'companyCommissions'])->middleware(EnsurePermission::class.':kams.finance.company.view');
Route::get('finance/company-accounts/{treasuryAccount}/commissions/{member}', [KamsFinanceController::class, 'companyCommissionDetail'])->middleware(EnsurePermission::class.':kams.finance.company.view');
Route::post('finance/company-accounts/{treasuryAccount}/commissions/{member}/transfer', [KamsFinanceController::class, 'companyCommissionTransfer'])->middleware(EnsurePermission::class.':kams.finance.company.deposit');
Route::get('finance/company-accounts/{treasuryAccount}/fixed-charges', [KamsFinanceController::class, 'fixedCharges'])->middleware(EnsurePermission::class.':kams.finance.company.view');
Route::post('finance/company-accounts/{treasuryAccount}/fixed-charges', [KamsFinanceController::class, 'storeFixedCharge'])->middleware(EnsurePermission::class.':kams.finance.company.expense');
Route::put('finance/company-accounts/{treasuryAccount}/fixed-charges/{fixedCharge}', [KamsFinanceController::class, 'updateFixedCharge'])->middleware(EnsurePermission::class.':kams.finance.company.expense');
Route::delete('finance/company-accounts/{treasuryAccount}/fixed-charges/{fixedCharge}', [KamsFinanceController::class, 'destroyFixedCharge'])->middleware(EnsurePermission::class.':kams.finance.company.expense');
Route::post('finance/company-accounts/{treasuryAccount}/deposit', [KamsFinanceController::class, 'companyDeposit'])->middleware(EnsurePermission::class.':kams.finance.company.deposit');
Route::post('finance/company-accounts/{treasuryAccount}/withdraw', [KamsFinanceController::class, 'companyWithdraw'])->middleware(EnsurePermission::class.':kams.finance.company.withdraw');
Route::post('finance/company-accounts/{treasuryAccount}/expense', [KamsFinanceController::class, 'companyExpense'])->middleware(EnsurePermission::class.':kams.finance.company.expense');

Route::get('depots/lookups', [KamsStockController::class, 'lookups'])->middleware(EnsurePermission::class.':kams.products.view,kams.stock.warehouses.view,kams.stock.depots.view');
Route::get('depots', [KamsResourceController::class, 'depots'])->middleware(EnsurePermission::class.':kams.products.view,kams.stock.warehouses.view,kams.stock.depots.view');
Route::post('depots', [KamsResourceController::class, 'storeDepot'])->middleware(EnsurePermission::class.':kams.products.create,kams.stock.warehouses.create,kams.stock.depots.create');
Route::put('depots/{depot}', [KamsResourceController::class, 'updateDepot'])->middleware(EnsurePermission::class.':kams.stock.warehouses.update,kams.products.update,kams.stock.depots.update');
Route::delete('depots/{depot}', [KamsResourceController::class, 'destroyDepot'])->middleware(EnsurePermission::class.':kams.stock.warehouses.delete,kams.stock.depots.delete');

Route::get('products', [KamsResourceController::class, 'products'])->middleware(EnsurePermission::class.':kams.products.view,kams.stock.warehouses.view,kams.stock.depots.view');
Route::post('products', [KamsResourceController::class, 'storeProduct'])->middleware(EnsurePermission::class.':kams.products.create');
Route::match(['put', 'post'], 'products/{product}', [KamsResourceController::class, 'updateProduct'])->middleware(EnsurePermission::class.':kams.products.update');
Route::put('products/{product}/office-price', [KamsResourceController::class, 'updateOfficePrice'])->middleware(EnsurePermission::class.':kams.products.update,kams.stock.depots.price');
Route::delete('products/{product}', [KamsResourceController::class, 'destroyProduct'])->middleware(EnsurePermission::class.':kams.products.delete');

Route::get('orders', [KamsResourceController::class, 'orders'])->middleware(EnsurePermission::class.':kams.orders.view');
Route::get('stock/purchase-orders', [KamsStockController::class, 'purchaseOrders'])->middleware(EnsurePermission::class.':kams.stock.orders.view');
Route::get('stock/purchase-orders/journal', [KamsStockController::class, 'purchaseOrderJournal'])->middleware(EnsurePermission::class.':kams.stock.orders.view');
Route::post('stock/purchase-orders', [KamsStockController::class, 'storePurchaseOrder'])->middleware(EnsurePermission::class.':kams.stock.orders.create');
Route::put('stock/purchase-orders/{purchaseOrder}', [KamsStockController::class, 'updatePurchaseOrder'])->middleware(EnsurePermission::class.':kams.stock.orders.update');
Route::delete('stock/purchase-orders/{purchaseOrder}', [KamsStockController::class, 'destroyPurchaseOrder'])->middleware(EnsurePermission::class.':kams.stock.orders.delete');
Route::post('stock/purchase-orders/{purchaseOrder}/receive', [KamsStockController::class, 'receivePurchaseOrder'])->middleware(EnsurePermission::class.':kams.stock.orders.receive');
Route::post('stock/purchase-orders/{purchaseOrder}/cancel', [KamsStockController::class, 'cancelPurchaseOrder'])->middleware(EnsurePermission::class.':kams.stock.orders.cancel');

Route::get('recruitment/lookups', [KamsRecruitmentController::class, 'lookups'])->middleware(EnsurePermission::class.':kams.members.agents.view,kams.recruitment.applications.view,kams.members.statistics.view,kams.recruitment.reports.view');
Route::get('recruitment/dashboard', [KamsRecruitmentController::class, 'dashboard'])->middleware(EnsurePermission::class.':kams.members.statistics.view');
Route::get('recruitment/communications', [KamsRecruitmentController::class, 'communications'])->middleware(EnsurePermission::class.':kams.recruitment.applications.view');

Route::get('candidates', [KamsRecruitmentController::class, 'candidates'])->middleware(EnsurePermission::class.':kams.recruitment.applications.view,kams.recruitment.reports.view');
Route::post('candidates', [KamsRecruitmentController::class, 'storeCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.create');
Route::get('candidates/{candidate}', [KamsRecruitmentController::class, 'showCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.applications.view,kams.recruitment.reports.view');
Route::match(['put', 'post'], 'candidates/{candidate}', [KamsRecruitmentController::class, 'updateCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.update');
Route::delete('candidates/{candidate}', [KamsRecruitmentController::class, 'destroyCandidate'])->middleware(EnsurePermission::class.':kams.recruitment.delete');

Route::post('interviews', [KamsRecruitmentController::class, 'storeInterview'])->middleware(EnsurePermission::class.':kams.recruitment.interview.update');
Route::post('observation-days', [KamsRecruitmentController::class, 'storeObservation'])->middleware(EnsurePermission::class.':kams.recruitment.observation.update');

Route::get('members', [KamsRecruitmentController::class, 'members'])->middleware(EnsurePermission::class.':kams.members.agents.view');
Route::post('members', [KamsRecruitmentController::class, 'storeMember'])->middleware(EnsurePermission::class.':kams.members.create');
Route::get('members/{member}', [KamsRecruitmentController::class, 'showMember'])->middleware(EnsurePermission::class.':kams.members.agents.view');
Route::match(['put', 'post'], 'members/{member}', [KamsRecruitmentController::class, 'updateMember'])->middleware(EnsurePermission::class.':kams.members.update');
Route::post('members/{member}/account', [KamsRecruitmentController::class, 'setMemberAccount'])->middleware(EnsurePermission::class.':kams.members.activate');
Route::post('members/{member}/password', [KamsRecruitmentController::class, 'setMemberPassword'])->middleware(EnsurePermission::class.':kams.members.update');
Route::delete('members/{member}', [KamsRecruitmentController::class, 'destroyMember'])->middleware(EnsurePermission::class.':kams.members.delete');
Route::post('members/{member}/first-week', [KamsRecruitmentController::class, 'completeFirstWeek'])->middleware(EnsurePermission::class.':kams.members.update');
Route::post('members/{member}/opening-positions', [KamsRecruitmentController::class, 'storeOpeningPosition'])->middleware(EnsurePermission::class.':kams.members.create');

Route::get('member-imports', [KamsRecruitmentController::class, 'imports'])->middleware(EnsurePermission::class.':kams.members.agents.view');
Route::post('member-imports', [KamsRecruitmentController::class, 'importMembers'])->middleware(EnsurePermission::class.':kams.members.import');
Route::post('member-import-rows/{row}/verify', [KamsRecruitmentController::class, 'verifyImportRow'])->middleware(EnsurePermission::class.':kams.members.update');
Route::post('member-import-rows/{row}/activate', [KamsRecruitmentController::class, 'activateImportRow'])->middleware(EnsurePermission::class.':kams.members.activate');
