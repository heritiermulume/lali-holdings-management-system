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
use App\Modules\Kams\Models\KamsStockAdjustment;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockMovement;
use App\Modules\Kams\Models\KamsStockReceipt;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockTransfer;
use App\Modules\Kams\Models\KamsSavingsEntry;
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
                ->get(['id', 'company_id', 'name', 'sku', 'price', 'field_price', 'manager_price', 'company_price', 'cost', 'avg_cost', 'seller_share_percent', 'stock', 'image_path', 'description']),
            'clients' => KamsClient::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($request->user()->isOfficeScoped(), fn ($q) => $q->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]))
                ->where('kind', 'client')
                ->orderBy('name')
                ->get(['id', 'company_id', 'office_id', 'name']),
            'suppliers' => KamsClient::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->where('kind', 'supplier')
                ->where('status', 'active')
                ->with(['warehouses:id'])
                ->orderBy('name')
                ->get(['id', 'company_id', 'office_id', 'name'])
                ->map(fn (KamsClient $supplier) => [
                    'id' => $supplier->id,
                    'company_id' => $supplier->company_id,
                    'office_id' => $supplier->office_id,
                    'name' => $supplier->name,
                    'warehouse_ids' => $supplier->warehouses->pluck('id')->values(),
                ]),
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
        $data = $request->validate([
            'warehouse_id' => ['required_without:depot_id', 'nullable', 'exists:kams_depots,id'],
            'depot_id' => ['required_without:warehouse_id', 'nullable', 'exists:kams_depots,id'],
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
        $locationId = (int) ($data['warehouse_id'] ?? $data['depot_id']);
        $location = KamsDepot::query()->whereIn('kind', ['warehouse', 'depot'])->findOrFail($locationId);
        $this->assertCompany($request, (int) $location->company_id);
        if ($location->isWarehouse()) {
            abort_unless($this->canManageWarehouses($request), 403);
        } elseif ($location->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $location->office_id), 403);
        }
        $items = $data['items'] ?? [[
            'product_id' => (int) $data['product_id'],
            'quantity' => (int) $data['quantity'],
            'unit_cost' => (float) ($data['unit_cost'] ?? KamsProduct::query()->findOrFail($data['product_id'])->cost),
        ]];
        $receipt = $this->inventory->createReceipt($location, $items, $data['supplier_name'] ?? null, $request->user()->id, $data['notes'] ?? null);
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
            ->with([
                'fromDepot:id,name,kind',
                'toDepot:id,name,kind,office_id',
                'toDepot.office:id,name',
                'items.product:id,name,sku',
                'createdBy:id,name',
                'dispatchedBy:id,name',
                'receivedBy:id,name',
            ])
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
            'auto_receive' => ['nullable', 'boolean'],
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

        $autoReceive = array_key_exists('auto_receive', $data) ? (bool) $data['auto_receive'] : true;
        if ($autoReceive && $transfer->status === 'in_transit') {
            $transfer = $this->inventory->receiveTransfer($transfer, $request->user()->id);
            AuditLogger::record('update', $transfer, [], ['status' => 'received', 'auto_receive' => true]);
        }

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
        $search = trim($request->string('search')->toString());
        $query = KamsStockAssignment::query()
            ->with(['member:id,full_name,member_code,office_id', 'office:id,name,code', 'depot:id,name', 'items.product:id,name,sku,price,cost,image_path'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->user()->isOfficeScoped(), fn ($q) => $q->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('reference', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('member', function ($member) use ($search) {
                            $member->where('full_name', 'like', "%{$search}%")
                                ->orWhere('member_code', 'like', "%{$search}%");
                        })
                        ->orWhereHas('office', function ($office) use ($search) {
                            $office->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%");
                        })
                        ->orWhereHas('items.product', function ($product) use ($search) {
                            $product->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        });
                });
            })
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function assignmentsReport(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'period' => ['nullable', 'in:day,week,month,custom'],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
        ]);

        $from = \Illuminate\Support\Carbon::parse($data['from'])->startOfDay();
        $to = \Illuminate\Support\Carbon::parse($data['to'])->endOfDay();

        $filterOffices = $this->kamsOffices($request)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $canFilterOffices = $filterOffices->count() > 1;

        $selectedOfficeId = null;
        $selectedOfficeName = null;
        if ($canFilterOffices && ! empty($data['office_id'])) {
            $office = $this->assertKamsOffice($request, (int) $data['office_id']);
            $selectedOfficeId = (int) $office->id;
            $selectedOfficeName = $office->name;
        } elseif (! $canFilterOffices && $filterOffices->count() === 1) {
            $office = $filterOffices->first();
            $selectedOfficeId = (int) $office->id;
            $selectedOfficeName = $office->name;
        }

        $assignments = KamsStockAssignment::query()
            ->with([
                'member:id,full_name,member_code,office_id',
                'office:id,name,code',
                'depot:id,name',
                'items.product:id,name,sku,price,cost,image_path',
            ])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->user()->isOfficeScoped(), fn ($q) => $q->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]))
            ->when($selectedOfficeId && $canFilterOffices, fn ($q) => $q->where('office_id', $selectedOfficeId))
            ->where('status', 'settled')
            ->whereBetween('settled_at', [$from, $to])
            ->orderByDesc('settled_at')
            ->orderByDesc('id')
            ->get();

        $days = [];
        $totals = [
            'assignments' => 0,
            'agents' => [],
            'expected_amount' => 0.0,
            'collected_amount' => 0.0,
            'profit_amount' => 0.0,
            'loss_amount' => 0.0,
            'assigned_units' => 0,
            'sold_units' => 0,
            'returned_units' => 0,
            'lost_units' => 0,
        ];

        foreach ($assignments as $assignment) {
            $date = optional($assignment->settled_at)->toDateString() ?: optional($assignment->assigned_at)->toDateString();
            if (! $date) {
                continue;
            }

            if (! isset($days[$date])) {
                $days[$date] = [
                    'date' => $date,
                    'totals' => [
                        'assignments' => 0,
                        'agents' => [],
                        'expected_amount' => 0.0,
                        'collected_amount' => 0.0,
                        'profit_amount' => 0.0,
                        'loss_amount' => 0.0,
                        'assigned_units' => 0,
                        'sold_units' => 0,
                        'returned_units' => 0,
                        'lost_units' => 0,
                    ],
                    'assignments' => [],
                ];
            }

            $assignedUnits = 0;
            $soldUnits = 0;
            $returnedUnits = 0;
            $lostUnits = 0;
            foreach ($assignment->items as $item) {
                $assignedUnits += (int) $item->quantity_out;
                $returnedUnits += (int) $item->quantity_returned;
                $lostUnits += (int) $item->quantity_lost;
                $soldUnits += max(0, (int) $item->quantity_out - (int) $item->quantity_returned - (int) $item->quantity_lost);
            }

            $expected = (float) $assignment->expected_amount;
            $collected = (float) $assignment->collected_amount;
            $profit = (float) $assignment->profit_amount;
            $loss = (float) $assignment->loss_amount;
            $memberId = $assignment->member_id;

            $days[$date]['totals']['assignments']++;
            $days[$date]['totals']['expected_amount'] += $expected;
            $days[$date]['totals']['collected_amount'] += $collected;
            $days[$date]['totals']['profit_amount'] += $profit;
            $days[$date]['totals']['loss_amount'] += $loss;
            $days[$date]['totals']['assigned_units'] += $assignedUnits;
            $days[$date]['totals']['sold_units'] += $soldUnits;
            $days[$date]['totals']['returned_units'] += $returnedUnits;
            $days[$date]['totals']['lost_units'] += $lostUnits;
            if ($memberId) {
                $days[$date]['totals']['agents'][$memberId] = true;
            }
            $days[$date]['assignments'][] = $assignment;

            $totals['assignments']++;
            $totals['expected_amount'] += $expected;
            $totals['collected_amount'] += $collected;
            $totals['profit_amount'] += $profit;
            $totals['loss_amount'] += $loss;
            $totals['assigned_units'] += $assignedUnits;
            $totals['sold_units'] += $soldUnits;
            $totals['returned_units'] += $returnedUnits;
            $totals['lost_units'] += $lostUnits;
            if ($memberId) {
                $totals['agents'][$memberId] = true;
            }
        }

        krsort($days);

        $normalize = function (array $row): array {
            $row['agents'] = count($row['agents']);
            $row['expected_amount'] = round($row['expected_amount'], 2);
            $row['collected_amount'] = round($row['collected_amount'], 2);
            $row['profit_amount'] = round($row['profit_amount'], 2);
            $row['loss_amount'] = round($row['loss_amount'], 2);
            $row['surplus'] = round(max(0, $row['collected_amount'] - $row['expected_amount']), 2);
            $row['shortfall'] = round(max(0, $row['expected_amount'] - $row['collected_amount']), 2);

            return $row;
        };

        $dayRows = array_values(array_map(function (array $day) use ($normalize) {
            $day['totals'] = $normalize($day['totals']);

            return $day;
        }, $days));

        return $this->ok([
            'period' => $data['period'] ?? 'custom',
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'can_filter_offices' => $canFilterOffices,
            'office_id' => $selectedOfficeId,
            'office_name' => $selectedOfficeName,
            'filter_offices' => $canFilterOffices ? $filterOffices->map(fn ($office) => [
                'id' => $office->id,
                'name' => $office->name,
                'code' => $office->code,
            ])->values()->all() : [],
            'totals' => $normalize($totals),
            'days' => $dayRows,
        ]);
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

    public function addAssignmentItems(Request $request, KamsStockAssignment $assignment): JsonResponse
    {
        $this->assertCompany($request, (int) $assignment->company_id);
        abort_unless($request->user()->canAccessOffice((int) $assignment->office_id), 403);
        abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));
        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'collected_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);
        $updated = $this->inventory->addToAssignment(
            $assignment,
            $data['items'],
            (float) ($data['collected_amount'] ?? 0),
            $request->user()->id,
            $data['notes'] ?? null,
        );
        AuditLogger::record('update', $updated, [], $data);

        return $this->ok($updated);
    }

    public function updateAssignment(Request $request, KamsStockAssignment $assignment): JsonResponse
    {
        $this->assertCompany($request, (int) $assignment->company_id);
        abort_unless($request->user()->canAccessOffice((int) $assignment->office_id), 403);
        abort_unless($assignment->status === 'open', 422, __('This assignment is already settled.'));
        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'exists:kams_stock_assignment_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);
        $updated = $this->inventory->updateAssignment(
            $assignment,
            $data['items'],
            $request->user()->id,
            $data['notes'] ?? null,
        );
        AuditLogger::record('update', $updated, [], $data);

        return $this->ok($updated);
    }

    public function settleAssignment(Request $request, KamsStockAssignment $assignment): JsonResponse
    {
        $this->assertCompany($request, (int) $assignment->company_id);
        abort_unless($request->user()->canAccessOffice((int) $assignment->office_id), 403);
        $data = $request->validate([
            'collected_amount' => ['required', 'numeric', 'min:0'],
            'deposit_surplus' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'exists:kams_stock_assignment_items,id'],
            'items.*.quantity_returned' => ['required', 'integer', 'min:0'],
            'items.*.quantity_lost' => ['required', 'integer', 'min:0'],
        ]);
        $settled = $this->inventory->settle(
            $assignment,
            $data['items'],
            (float) $data['collected_amount'],
            $data['notes'] ?? null,
            $request->user()->id,
            (bool) ($data['deposit_surplus'] ?? false),
        );
        AuditLogger::record('update', $settled, [], $data);
        if ($settled->getAttribute('surplus_entry')) {
            $entryId = data_get($settled->getAttribute('surplus_entry'), 'id');
            if ($entryId) {
                $entry = KamsSavingsEntry::query()->find($entryId);
                if ($entry) {
                    AuditLogger::record('create', $entry, [], [
                        'reason' => 'day_closing_surplus',
                        'assignment_id' => $settled->id,
                        'amount' => $settled->getAttribute('surplus_deposited'),
                    ]);
                }
            }
        }

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

    public function adjustments(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsStockAdjustment::query()
            ->with([
                'depot:id,name,kind,office_id',
                'product:id,name,sku',
                'creator:id,name',
            ])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->filled('depot_id'), fn ($q) => $q->where('depot_id', (int) $request->integer('depot_id')))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->integer('product_id')))
            ->latest('adjusted_at');

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function movements(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $depotId = (int) ($request->integer('depot_id') ?: $request->integer('warehouse_id'));
        abort_unless($depotId > 0, 422, __('A warehouse or depot is required.'));

        $location = KamsDepot::query()->findOrFail($depotId);
        $this->assertCompany($request, (int) $location->company_id);
        if ($location->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $location->office_id), 403);
        }

        $direction = $request->string('direction')->toString();
        $search = trim($request->string('search')->toString());

        $query = KamsStockMovement::query()
            ->with([
                'product:id,name,sku',
                'fromDepot:id,name,kind,code',
                'toDepot:id,name,kind,code',
                'member:id,full_name,member_code',
                'user:id,name,first_name,last_name',
                'approvedBy:id,name,first_name,last_name',
                'reference',
            ])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($direction === 'in', fn ($q) => $q->where('to_depot_id', $depotId)->where(function ($inner) use ($depotId) {
                $inner->whereNull('from_depot_id')->orWhere('from_depot_id', '!=', $depotId);
            }))
            ->when($direction === 'out', fn ($q) => $q->where('from_depot_id', $depotId)->where(function ($inner) use ($depotId) {
                $inner->whereNull('to_depot_id')->orWhere('to_depot_id', '!=', $depotId);
            }))
            ->when($direction === 'internal', fn ($q) => $q->where('from_depot_id', $depotId)->where('to_depot_id', $depotId))
            ->when(! in_array($direction, ['in', 'out', 'internal'], true), function ($q) use ($depotId) {
                $q->where(function ($inner) use ($depotId) {
                    $inner->where('from_depot_id', $depotId)->orWhere('to_depot_id', $depotId);
                });
            })
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->integer('product_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('occurred_at', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('occurred_at', '<=', $request->date('to')->toDateString()))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('notes', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($product) use ($search) {
                            $product->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        })
                        ->orWhereHas('user', function ($user) use ($search) {
                            $user->where('name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('approvedBy', function ($user) use ($search) {
                            $user->where('name', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('member', function ($member) use ($search) {
                            $member->where('full_name', 'like', "%{$search}%")
                                ->orWhere('member_code', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('occurred_at')
            ->latest('id');

        $paginator = $query->paginate($request->integer('per_page', 50));
        $paginator->getCollection()->transform(function (KamsStockMovement $movement) use ($depotId) {
            $document = $movement->reference;
            $movement->setAttribute('document_reference', is_object($document) ? ($document->reference ?? null) : null);
            $movement->setAttribute(
                'direction',
                (int) $movement->to_depot_id === $depotId && (int) $movement->from_depot_id !== $depotId
                    ? 'in'
                    : ((int) $movement->from_depot_id === $depotId && (int) $movement->to_depot_id !== $depotId
                        ? 'out'
                        : 'internal'),
            );
            $movement->setAttribute('performed_by', $this->movementPerformer($movement, $document));
            $movement->unsetRelation('reference');

            return $movement;
        });

        return $this->paginated($paginator, 'OK', [
            'location' => $location->only(['id', 'name', 'code', 'kind', 'status']),
        ]);
    }

    /**
     * @return array{id: int|null, name: string}|null
     */
    private function movementPerformer(KamsStockMovement $movement, mixed $document): ?array
    {
        $candidates = [
            $movement->user,
            $movement->approvedBy,
        ];

        if (is_object($document)) {
            foreach (['createdBy', 'dispatchedBy', 'validatedBy', 'receivedBy', 'creator', 'performedBy'] as $relation) {
                if (method_exists($document, $relation)) {
                    $document->loadMissing("{$relation}:id,name,first_name,last_name");
                    $candidates[] = $document->{$relation};
                }
            }
        }

        foreach ($candidates as $person) {
            if (! is_object($person)) {
                continue;
            }
            $name = trim((string) ($person->name ?? ''))
                ?: trim(trim((string) ($person->first_name ?? '')).' '.trim((string) ($person->last_name ?? '')));
            if ($name !== '') {
                return [
                    'id' => isset($person->id) ? (int) $person->id : null,
                    'name' => $name,
                ];
            }
        }

        return null;
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'depot_id' => ['required', 'exists:kams_depots,id'],
            'product_id' => ['required', 'exists:kams_products,id'],
            'type' => ['required', 'in:defective,lost,found'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $location = KamsDepot::query()->findOrFail($data['depot_id']);
        $product = KamsProduct::query()->findOrFail($data['product_id']);
        $this->assertCompany($request, (int) $location->company_id);
        if ($location->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $location->office_id), 403);
        }

        $adjustment = $this->inventory->adjust(
            $location,
            $product,
            $data['type'],
            (int) $data['quantity'],
            $data['reason'],
            $request->user()->id,
        );
        AuditLogger::record('create', $adjustment, [], $data);

        return $this->created($adjustment);
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
        abort_unless(in_array($kind, ['guy', 'manager', 'assistant_manager', 'secretary', 'divisionnaire'], true), 422);

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
