<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Core\Support\AuditLogger;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Province;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockReceipt;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockTransfer;
use App\Modules\Kams\Services\SavingsLedger;
use App\Modules\Kams\Services\StockInventory;
use App\Modules\Kams\Services\StockMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KamsStockController extends Controller
{
    use ResolvesKamsOffices;
    use RespondsWithJson;

    public function __construct(private readonly StockInventory $inventory) {}

    public function lookups(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request) ?? $request->user()->primary_company_id;
        if ($companyId) {
            $this->inventory->ensureOfficeDepots((int) $companyId);
        }

        $offices = $this->kamsOffices($request)->with('city:id,name')->orderBy('name')->get(['id', 'company_id', 'name', 'code', 'city_id', 'province_id']);
        $locationQuery = KamsDepot::query()
            ->with(['office:id,name,code', 'province:id,name,name_fr'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        if ($request->user()->isOfficeScoped()) {
            $locationQuery->where(function ($q) use ($request) {
                $q->where('kind', 'warehouse')
                    ->orWhereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]);
            });
        }

        $locations = $locationQuery->orderBy('name')->get();

        return $this->ok([
            'offices' => $offices,
            'can_choose_office' => $this->canChooseOffice($request, $offices->count()),
            'can_manage_warehouses' => $this->canManageWarehouses($request),
            'provinces' => Province::query()->orderBy('name')->get(['id', 'name', 'name_fr', 'code']),
            'warehouses' => $locations->where('kind', 'warehouse')->values(),
            'depots' => $locations->where('kind', 'depot')->values(),
            'sellers' => $locations->where('kind', 'seller')->values(),
            'products' => KamsProduct::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'company_id', 'name', 'sku', 'price', 'field_price', 'manager_price', 'company_price', 'cost', 'avg_cost', 'seller_share_percent', 'stock']),
            'clients' => KamsClient::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($request->user()->isOfficeScoped(), fn ($q) => $q->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]))
                ->orderBy('name')
                ->get(['id', 'company_id', 'office_id', 'name']),
            'agents' => KamsMember::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($request->user()->isOfficeScoped(), fn ($q) => $q->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]))
                ->where('status', 'active')
                ->orderBy('full_name')
                ->get(['id', 'company_id', 'office_id', 'member_code', 'full_name']),
        ]);
    }

    public function dashboard(Request $request, StockMetrics $metrics): JsonResponse
    {
        return $this->ok($metrics->recap(
            $request->string('group_by')->toString() ?: 'week',
            $this->kamsCompanyId($request),
        ));
    }

    public function receipts(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsStockReceipt::query()
            ->with(['warehouse:id,name,kind', 'items.product:id,name,sku', 'createdBy:id,name', 'validatedBy:id,name'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function receive(Request $request): JsonResponse
    {
        abort_unless($this->canManageWarehouses($request), 403);
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:kams_depots,id'],
            'supplier_name' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'validate' => ['nullable', 'boolean'],
            'product_id' => ['required_without:items', 'exists:kams_products,id'],
            'quantity' => ['required_without:items', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required_without:product_id', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        $warehouse = KamsDepot::query()->where('kind', 'warehouse')->findOrFail($data['warehouse_id']);
        $this->assertCompany($request, (int) $warehouse->company_id);
        $items = $data['items'] ?? [[
            'product_id' => (int) $data['product_id'],
            'quantity' => (int) $data['quantity'],
            'unit_cost' => (float) ($data['unit_cost'] ?? KamsProduct::query()->findOrFail($data['product_id'])->cost),
        ]];
        $receipt = $this->inventory->createReceipt($warehouse, $items, $data['supplier_name'] ?? null, $request->user()->id, $data['notes'] ?? null);
        if ($request->boolean('validate', true)) {
            $receipt = $this->inventory->validateReceipt($receipt, $request->user()->id);
        }
        AuditLogger::record('create', $receipt, [], $data);

        return $this->created($receipt);
    }

    public function validateReceipt(Request $request, KamsStockReceipt $receipt): JsonResponse
    {
        abort_unless($this->canManageWarehouses($request), 403);
        $this->assertCompany($request, (int) $receipt->company_id);
        $validated = $this->inventory->validateReceipt($receipt, $request->user()->id);
        AuditLogger::record('update', $validated, [], ['status' => 'received']);

        return $this->ok($validated);
    }

    public function cancelReceipt(Request $request, KamsStockReceipt $receipt): JsonResponse
    {
        abort_unless($this->canManageWarehouses($request), 403);
        $this->assertCompany($request, (int) $receipt->company_id);
        $cancelled = $this->inventory->cancelReceipt($receipt);
        AuditLogger::record('update', $cancelled, [], ['status' => 'cancelled']);

        return $this->ok($cancelled);
    }

    public function transfers(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsStockTransfer::query()
            ->with(['fromDepot:id,name,kind', 'toDepot:id,name,kind,office_id', 'toDepot.office:id,name', 'items.product:id,name,sku'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->user()->isOfficeScoped(), function ($q) use ($request) {
                $q->whereHas('toDepot', fn ($depot) => $depot->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]));
            })
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_depot_id' => ['required', 'exists:kams_depots,id'],
            'to_depot_id' => ['required', 'exists:kams_depots,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $from = KamsDepot::query()->findOrFail($data['from_depot_id']);
        $to = KamsDepot::query()->findOrFail($data['to_depot_id']);
        $this->assertCompany($request, (int) $from->company_id);
        if ($to->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $to->office_id), 403);
        }
        $transfer = $this->inventory->transfer($from, $to, $data['items'], $request->user()->id, $data['notes'] ?? null);
        AuditLogger::record('create', $transfer, [], $data);

        return $this->created($transfer);
    }

    public function receiveTransfer(Request $request, KamsStockTransfer $transfer): JsonResponse
    {
        $this->assertCompany($request, (int) $transfer->company_id);
        $transfer->loadMissing('toDepot');
        if ($transfer->toDepot?->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $transfer->toDepot->office_id), 403);
        }
        $received = $this->inventory->receiveTransfer($transfer, $request->user()->id);
        AuditLogger::record('update', $received, [], ['status' => 'received']);

        return $this->ok($received);
    }

    public function assignments(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsStockAssignment::query()
            ->with(['member:id,full_name,member_code,office_id', 'office:id,name,code', 'depot:id,name', 'items.product:id,name,sku,price,cost'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->user()->isOfficeScoped(), fn ($q) => $q->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'member_id' => ['required', 'exists:kams_members,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $member = KamsMember::query()->findOrFail($data['member_id']);
        $this->assertCompany($request, (int) $member->company_id);
        if ($member->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $member->office_id), 403);
        }
        $assignment = $this->inventory->assign($member, $data['items'], $request->user()->id, $data['notes'] ?? null);
        AuditLogger::record('create', $assignment, [], $data);

        return $this->created($assignment);
    }

    public function settleAssignment(Request $request, KamsStockAssignment $assignment): JsonResponse
    {
        $this->assertCompany($request, (int) $assignment->company_id);
        abort_unless($request->user()->canAccessOffice((int) $assignment->office_id), 403);
        $data = $request->validate([
            'collected_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'exists:kams_stock_assignment_items,id'],
            'items.*.quantity_returned' => ['required', 'integer', 'min:0'],
            'items.*.quantity_lost' => ['required', 'integer', 'min:0'],
        ]);
        $settled = $this->inventory->settle($assignment, $data['items'], (float) $data['collected_amount'], $data['notes'] ?? null, $request->user()->id);
        AuditLogger::record('update', $settled, [], $data);

        return $this->ok($settled);
    }

    public function sales(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsStockSale::query()
            ->with(['member:id,full_name,member_code,office_id', 'client:id,name', 'depot:id,name', 'items.product:id,name,sku', 'commission'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->user()->isOfficeScoped(), function ($q) use ($request) {
                $q->whereHas('member', fn ($member) => $member->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]));
            })
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function paySale(Request $request, KamsStockSale $sale): JsonResponse
    {
        $this->assertCompany($request, (int) $sale->company_id);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $paid = $this->inventory->paySale($sale, (float) $data['amount']);
        AuditLogger::record('update', $paid, [], $data);

        return $this->ok($paid);
    }

    public function returns(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsStockReturn::query()
            ->with(['member:id,full_name,member_code', 'sale:id,reference', 'fromDepot:id,name,kind', 'toDepot:id,name,kind', 'items.product:id,name,sku'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function storeReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:customer,seller_to_depot,depot_to_warehouse,supplier,defective'],
            'member_id' => ['nullable', 'exists:kams_members,id'],
            'sale_id' => ['nullable', 'exists:kams_stock_sales,id'],
            'from_depot_id' => ['nullable', 'exists:kams_depots,id'],
            'to_depot_id' => ['nullable', 'exists:kams_depots,id'],
            'condition' => ['nullable', 'in:available,quarantine,damaged'],
            'reason' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $member = isset($data['member_id']) ? KamsMember::query()->findOrFail($data['member_id']) : null;
        $sale = isset($data['sale_id']) ? KamsStockSale::query()->findOrFail($data['sale_id']) : null;
        $from = isset($data['from_depot_id']) ? KamsDepot::query()->findOrFail($data['from_depot_id']) : null;
        $to = isset($data['to_depot_id']) ? KamsDepot::query()->findOrFail($data['to_depot_id']) : null;
        $companyId = $member?->company_id ?? $sale?->company_id ?? $from?->company_id ?? $to?->company_id;
        $this->assertCompany($request, (int) $companyId);
        $return = $this->inventory->createReturn(
            $data['type'],
            $data['items'],
            $member,
            $sale,
            $from,
            $to,
            $data['condition'] ?? 'quarantine',
            $data['reason'] ?? '',
            $request->user()->id,
        );
        AuditLogger::record('create', $return, [], $data);

        return $this->created($return);
    }

    public function acceptReturn(Request $request, KamsStockReturn $stockReturn): JsonResponse
    {
        $this->assertCompany($request, (int) $stockReturn->company_id);
        $accepted = $this->inventory->acceptReturn($stockReturn, $request->user()->id);
        AuditLogger::record('update', $accepted, [], ['status' => $accepted->status]);

        return $this->ok($accepted);
    }

    public function commissions(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsSellerCommission::query()
            ->with(['member:id,full_name,member_code', 'sale:id,reference,subtotal,margin,payment_status'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function payCommission(Request $request, KamsSellerCommission $commission): JsonResponse
    {
        $this->assertCompany($request, (int) $commission->company_id);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $paid = $this->inventory->payCommission($commission, (float) $data['amount']);
        AuditLogger::record('update', $paid, [], $data);

        return $this->ok($paid);
    }

    public function hierarchy(Request $request, StockMetrics $metrics): JsonResponse
    {
        return $this->ok($metrics->hierarchy($this->kamsCompanyId($request)));
    }

    public function savings(Request $request, SavingsLedger $ledger): JsonResponse
    {
        $kind = $request->string('kind')->toString() ?: 'guy';
        abort_unless(in_array($kind, ['guy', 'manager'], true), 422);

        return $this->ok($ledger->ledgers($this->kamsCompanyId($request), $kind));
    }

    public function dailySettlement(Request $request, SavingsLedger $ledger): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        abort_unless($companyId, 422, __('No company is available for this account.'));
        $date = $request->date('date')?->toDateString() ?: now()->toDateString();
        $officeId = $request->filled('office_id') ? (int) $request->integer('office_id') : null;
        if ($officeId) {
            abort_unless($request->user()->canAccessOffice($officeId), 403);
        }
        $recap = $ledger->recapDay($companyId, $date, $officeId);
        $recap['sales'] = KamsStockSale::query()
            ->with(['member:id,full_name', 'items.product:id,name'])
            ->where('company_id', $companyId)
            ->whereDate('sold_at', $date)
            ->when($officeId, fn ($q) => $q->whereHas('member', fn ($m) => $m->where('office_id', $officeId)))
            ->get();

        return $this->ok($recap);
    }

    public function closeDailySettlement(Request $request, SavingsLedger $ledger): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        abort_unless($companyId, 422, __('No company is available for this account.'));
        $data = $request->validate([
            'date' => ['required', 'date'],
            'office_id' => ['nullable', 'exists:offices,id'],
        ]);
        if (! empty($data['office_id'])) {
            abort_unless($request->user()->canAccessOffice((int) $data['office_id']), 403);
        }
        $closed = $ledger->closeDay($companyId, $data['date'], $data['office_id'] ?? null, $request->user()->id);
        AuditLogger::record('create', $closed, [], $data);

        return $this->ok($closed);
    }
}
