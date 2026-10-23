<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Company;
use App\Models\Province;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsPurchaseOrder;
use App\Modules\Kams\Models\KamsPurchaseOrderItem;
use App\Modules\Kams\Models\KamsSellerCommission;
use App\Modules\Kams\Models\KamsStockAdjustment;
use App\Modules\Kams\Models\KamsStockAssignment;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Models\KamsStockMovement;
use App\Modules\Kams\Models\KamsStockReceipt;
use App\Modules\Kams\Models\KamsStockReturn;
use App\Modules\Kams\Models\KamsStockSale;
use App\Modules\Kams\Models\KamsStockTransfer;
use App\Modules\Kams\Models\KamsDailySettlement;
use App\Modules\Kams\Models\KamsSavingsEntry;
use App\Modules\Kams\Models\KamsStockJournalEntry;
use App\Modules\Kams\Services\SavingsLedger;
use App\Modules\Kams\Services\StockInventory;
use App\Modules\Kams\Services\StockJournal;
use App\Modules\Kams\Services\StockMetrics;
use App\Modules\Kams\Support\DomainOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $officeState = $this->resolveOfficeFilterState($request, $companyId ? (int) $companyId : null);
        $offices = $officeState['offices'];
        $officeScope = $officeState['office_ids'] !== [] ? $officeState['office_ids'] : null;
        $locationQuery = KamsDepot::query()
            ->with(['office:id,name,code', 'province:id,name,name_fr'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));

        // Office depots/sellers follow office access; regional warehouses stay visible
        // for office-scoped roles that have stock.warehouses.view (e.g. managers).
        if ($officeScope !== null) {
            $locationQuery->where(function ($q) use ($officeScope) {
                $q->whereIn('office_id', $officeScope)->orWhere('kind', 'warehouse');
            });
        }

        $locations = $locationQuery->orderBy('name')->get();

        $company = $companyId
            ? Company::query()->find($companyId, ['id', 'name', 'currency_code'])
            : null;

        return $this->ok([
            ...app(\App\Modules\Kams\Services\KamsDisplayCurrency::class)->forState($officeState, $companyId),
            'company_id' => $company?->id,
            'offices' => $offices,
            'can_choose_office' => $officeState['can_filter'],
            'can_filter_offices' => $officeState['can_filter'],
            'office_id' => $officeState['selected_office_id'],
            'can_manage_warehouses' => $this->canManageWarehouses($request),
            'provinces' => Province::query()->orderBy('name')->get(['id', 'name', 'name_fr', 'code']),
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_fr', 'province_id']),
            'warehouses' => $locations->where('kind', 'warehouse')->values(),
            'depots' => $locations->where('kind', 'depot')->values(),
            'sellers' => $locations->where('kind', 'seller')->values(),
            'products' => $this->lookupProductsWithOfficeStock($companyId ? (int) $companyId : null, $officeScope),
            'clients' => KamsClient::query()
                ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                ->when($officeScope !== null, fn ($q) => $q->whereIn('office_id', $officeScope))
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
                ->when($officeScope !== null, fn ($q) => $q->whereIn('office_id', $officeScope))
                ->where('status', 'active')
                ->assignableForStock()
                ->orderBy('full_name')
                ->get(['id', 'company_id', 'office_id', 'member_code', 'full_name', 'photo_path']),
            'options' => DomainOptions::all(),
        ]);
    }

    public function dashboard(Request $request, StockMetrics $metrics): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $officeIds = $officeState['office_ids'];

        $payload = $metrics->recap(
            $request->string('group_by')->toString() ?: 'week',
            $companyId,
            $request->string('group_by')->toString() === 'custom' ? $request->string('from')->toString() : null,
            $request->string('group_by')->toString() === 'custom' ? $request->string('to')->toString() : null,
            $officeIds,
        );
        $payload = array_merge($payload, $this->officeFilterPayload($officeState));

        return $this->ok($payload);
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
            'unit_cost' => (float) ($data['unit_cost'] ?? KamsProduct::query()->findOrFail($data['product_id'])->inventoryUnitCost()),
        ]];
        $receipt = $this->inventory->createReceipt($location, $items, $data['supplier_name'] ?? null, $request->user()->id, $data['notes'] ?? null);
        StockJournal::record('create', $receipt, [], $data);
        if ($request->boolean('validate', true)) {
            $receipt = $this->inventory->validateReceipt($receipt, $request->user()->id);
            StockJournal::record('update', $receipt, [], ['status' => 'received']);
        }

        return $this->created($receipt);
    }

    public function validateReceipt(Request $request, KamsStockReceipt $receipt): JsonResponse
    {
        abort_unless($this->canManageWarehouses($request), 403);
        $this->assertCompany($request, (int) $receipt->company_id);
        $validated = $this->inventory->validateReceipt($receipt, $request->user()->id);
        StockJournal::record('update', $validated, [], ['status' => 'received']);

        return $this->ok($validated);
    }

    public function cancelReceipt(Request $request, KamsStockReceipt $receipt): JsonResponse
    {
        abort_unless($this->canManageWarehouses($request), 403);
        $this->assertCompany($request, (int) $receipt->company_id);
        $cancelled = $this->inventory->cancelReceipt($receipt);
        StockJournal::record('update', $cancelled, [], ['status' => 'cancelled']);

        return $this->ok($cancelled);
    }

    public function transfers(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);
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
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('to_depot_id'), function ($q) use ($request) {
                $depot = KamsDepot::query()->findOrFail($request->integer('to_depot_id'));
                $this->assertCompany($request, (int) $depot->company_id);
                if ($depot->kind === 'warehouse') {
                    abort_unless($this->canManageWarehouses($request), 403);
                } elseif ($depot->office_id) {
                    abort_unless($request->user()->canAccessOffice((int) $depot->office_id), 403);
                }
                $q->where('to_depot_id', $depot->id);
            })
            ->when(! $request->filled('to_depot_id') && $officeState['office_ids'] !== [], function ($q) use ($officeState, $request) {
                $q->where(function ($inner) use ($officeState, $request) {
                    $inner->whereHas('toDepot', fn ($depot) => $depot->whereIn('office_id', $officeState['office_ids']));
                    if ($this->canManageWarehouses($request)) {
                        $inner->orWhereHas('fromDepot', fn ($depot) => $depot->where('kind', 'warehouse'));
                    }
                });
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
        abort_unless(in_array($to->kind, ['depot', 'warehouse'], true), 422, __('Transfers must arrive in a warehouse or office depot.'));
        if ($to->kind === 'warehouse') {
            abort_unless($this->canManageWarehouses($request), 403);
        } elseif ($to->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $to->office_id), 403);
        }
        $autoReceive = (bool) ($data['auto_receive'] ?? false);
        $transfer = $this->inventory->transfer(
            $from,
            $to,
            $data['items'],
            $request->user()->id,
            $data['notes'] ?? null,
            $autoReceive,
        );
        StockJournal::record('create', $transfer, [], [
            ...$data,
            'auto_receive' => $autoReceive,
            'status' => $transfer->status,
        ]);

        return $this->created($transfer);
    }

    public function receiveTransfer(Request $request, KamsStockTransfer $transfer): JsonResponse
    {
        $data = $request->validate([
            'confirm_loss' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity_received' => ['required', 'integer', 'min:0'],
        ]);
        $this->assertCompany($request, (int) $transfer->company_id);
        $transfer->loadMissing('toDepot');
        if ($transfer->toDepot?->kind === 'warehouse') {
            abort_unless($this->canManageWarehouses($request), 403);
        } elseif ($transfer->toDepot?->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $transfer->toDepot->office_id), 403);
        }
        $receivedByItemId = [];
        foreach ($data['items'] as $item) {
            $receivedByItemId[(int) $item['id']] = (int) $item['quantity_received'];
        }
        $received = $this->inventory->receiveTransfer(
            $transfer,
            $request->user()->id,
            $receivedByItemId,
            (bool) ($data['confirm_loss'] ?? false),
        );
        StockJournal::record('update', $received, [], [
            'status' => 'received',
            'items' => $data['items'],
            'confirm_loss' => (bool) ($data['confirm_loss'] ?? false),
        ]);

        return $this->ok($received);
    }

    public function cancelTransfer(Request $request, KamsStockTransfer $transfer): JsonResponse
    {
        $this->assertCompany($request, (int) $transfer->company_id);
        $transfer->loadMissing('fromDepot');
        abort_unless($transfer->fromDepot?->isWarehouse(), 422, __('Transfers start from a regional warehouse.'));
        abort_unless($this->canManageWarehouses($request), 403);
        $cancelled = $this->inventory->cancelTransfer($transfer, $request->user()->id);
        StockJournal::record('update', $cancelled, [], ['status' => 'cancelled']);

        return $this->ok($cancelled);
    }

    public function assignments(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $search = trim($request->string('search')->toString());
        $scoped = KamsStockAssignment::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $this->applyOfficeIds($scoped, $officeState);
        $query = KamsStockAssignment::query()
            ->with(['member:id,full_name,member_code,phone,office_id,photo_path', 'office:id,name,code', 'depot:id,name,office_id', 'items.product:id,name,sku,price,cost,image_path'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $this->applyOfficeIds($query, $officeState);
        $query = $query
            ->when($request->filled('status'), function ($q) use ($request) {
                $statuses = array_values(array_filter(array_map('trim', explode(',', $request->string('status')->toString()))));
                $q->whereIn('status', $statuses !== [] ? $statuses : ['open']);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('reference', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('member', function ($member) use ($search) {
                            $member->where('full_name', 'like', "%{$search}%")
                                ->orWhere('member_code', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
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

        return $this->paginated($query->paginate($request->integer('per_page', 50)), 'OK', [
            'open_count' => (clone $scoped)->where('status', 'open')->count(),
            'closed_count' => (clone $scoped)->where('status', 'closed')->count(),
        ]);
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

        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $selectedOfficeId = $officeState['selected_office_id'];
        $selectedOfficeName = $selectedOfficeId
            ? $officeState['offices']->firstWhere('id', $selectedOfficeId)?->name
            : null;

        $assignments = KamsStockAssignment::query()
            ->with([
                'member:id,full_name,member_code,phone,office_id',
                'office:id,name,code',
                'depot:id,name',
                'closedBy:id,name',
                'dayClosedBy:id,name',
                'items.product:id,name,sku,price,cost,image_path',
            ])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeState['office_ids'] !== [], fn ($q) => $q->whereIn('office_id', $officeState['office_ids']))
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
                    'day_closed_by' => [],
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
            $closer = $assignment->dayClosedBy?->name;
            if ($closer) {
                $days[$date]['day_closed_by'][$closer] = true;
            }

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
            $day['day_closed_by'] = implode(', ', array_keys($day['day_closed_by'] ?? []));

            return $day;
        }, $days));

        return $this->ok(array_merge([
            'period' => $data['period'] ?? 'custom',
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'office_name' => $selectedOfficeName,
            'can_delete' => $request->user()?->hasPermission('kams.stock.reports.delete') ?? false,
            'totals' => $normalize($totals),
            'days' => $dayRows,
        ], $this->officeFilterPayload($officeState)));
    }

    public function destroyAssignmentsReport(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
        ]);

        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $officeId = $officeState['selected_office_id'];
        $scopedIds = $request->user()->isOfficeScoped()
            ? ($request->user()->accessibleOfficeIds() ?: [0])
            : null;

        $result = $this->inventory->deleteDayReport(
            $companyId,
            \Illuminate\Support\Carbon::parse($data['date'])->toDateString(),
            $officeId,
            $scopedIds,
        );

        StockJournal::record('delete', null, array_merge($result, [
            'company_id' => $companyId,
            'office_id' => $officeId,
            'reason' => 'assignment_day_report_delete',
        ]), []);

        return $this->ok($result);
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
        StockJournal::record('create', $assignment, [], $data);

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
        $before = $this->assignmentAuditSnapshot($assignment);
        $updated = $this->inventory->addToAssignment(
            $assignment,
            $data['items'],
            (float) ($data['collected_amount'] ?? 0),
            $request->user()->id,
            $data['notes'] ?? null,
        );
        StockJournal::record('update', $updated, $before, $data);

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
        $before = $this->assignmentAuditSnapshot($assignment);
        $updated = $this->inventory->updateAssignment(
            $assignment,
            $data['items'],
            $request->user()->id,
            $data['notes'] ?? null,
        );
        StockJournal::record('update', $updated, $before, $data);

        return $this->ok($updated);
    }

    public function closeAssignment(Request $request, KamsStockAssignment $assignment): JsonResponse
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
        $before = $this->assignmentAuditSnapshot($assignment, true);
        $closed = $this->inventory->close(
            $assignment,
            $data['items'],
            (float) $data['collected_amount'],
            $data['notes'] ?? null,
            (bool) ($data['deposit_surplus'] ?? false),
            $request->user()->id,
        );
        StockJournal::record('update', $closed, $before, $data);

        return $this->ok($closed);
    }

    public function destroyAssignment(Request $request, KamsStockAssignment $assignment): JsonResponse
    {
        $this->assertCompany($request, (int) $assignment->company_id);
        abort_unless($request->user()->canAccessOffice((int) $assignment->office_id), 403);
        $before = $this->assignmentAuditSnapshot($assignment, true, true);
        $this->inventory->deleteAssignment($assignment, $request->user()->id);
        StockJournal::record('delete', $assignment, $before, []);

        return $this->ok(['deleted' => true, 'id' => $assignment->id]);
    }

    public function closeAssignmentDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
        ]);
        $officeId = isset($data['office_id']) ? (int) $data['office_id'] : null;
        if ($officeId) {
            abort_unless($request->user()->canAccessOffice($officeId), 403);
        }
        $companyId = $this->kamsCompanyId($request);
        $posted = $this->inventory->closeDay($companyId, $officeId, $request->user()->id, $request->user()->isOfficeScoped() ? ($request->user()->accessibleOfficeIds() ?: [0]) : null);
        foreach ($posted as $settled) {
            StockJournal::record('update', $settled, ['status' => 'closed'], ['status' => 'settled']);
            if ($sale = $settled->getAttribute('posted_sale')) {
                StockJournal::record('create', $sale, [], [
                    'reason' => 'assignment_sale',
                    'assignment_id' => $settled->id,
                    'office_id' => $settled->office_id,
                    'reference' => $sale->reference,
                ]);
            }
            if ($settled->getAttribute('surplus_entry')) {
                $entryId = data_get($settled->getAttribute('surplus_entry'), 'id');
                if ($entryId) {
                    $entry = KamsSavingsEntry::query()->find($entryId);
                    if ($entry) {
                        StockJournal::record('create', $entry, [], [
                            'reason' => 'day_closing_surplus',
                            'assignment_id' => $settled->id,
                            'office_id' => $settled->office_id,
                            'amount' => $settled->getAttribute('surplus_deposited'),
                        ]);
                    }
                }
            }
        }

        return $this->ok([
            'settled_count' => count($posted),
        ]);
    }

    public function sales(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $query = KamsStockSale::query()
            ->with(['member:id,full_name,member_code,office_id', 'client:id,name', 'depot:id,name', 'items.product:id,name,sku', 'commission'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($officeState['office_ids'] !== [], function ($q) use ($officeState) {
                $q->whereHas('member', fn ($member) => $member->whereIn('office_id', $officeState['office_ids']));
            })
            ->latest();

        return $this->paginated($query->paginate($request->integer('per_page', 50)));
    }

    public function paySale(Request $request, KamsStockSale $sale): JsonResponse
    {
        $this->assertCompany($request, (int) $sale->company_id);
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01']]);
        $paid = $this->inventory->paySale($sale, (float) $data['amount']);
        StockJournal::record('update', $paid, [], $data);

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
            'auto_accept' => ['nullable', 'boolean'],
        ]);
        $member = isset($data['member_id']) ? KamsMember::query()->findOrFail($data['member_id']) : null;
        $sale = isset($data['sale_id']) ? KamsStockSale::query()->findOrFail($data['sale_id']) : null;
        $from = isset($data['from_depot_id']) ? KamsDepot::query()->findOrFail($data['from_depot_id']) : null;
        $to = isset($data['to_depot_id']) ? KamsDepot::query()->findOrFail($data['to_depot_id']) : null;
        $companyId = $member?->company_id ?? $sale?->company_id ?? $from?->company_id ?? $to?->company_id;
        $this->assertCompany($request, (int) $companyId);

        if ($data['type'] === 'depot_to_warehouse') {
            abort_unless($from && $from->kind === 'depot', 422, __('Returns of this type must leave an office depot.'));
            if (! $to) {
                $productId = (int) ($data['items'][0]['product_id'] ?? 0);
                $to = $this->inventory->resolveReturnWarehouse($from, $productId ?: null);
            }
            abort_unless($to && $to->isWarehouse(), 422, __('Returns of this type must arrive in a warehouse.'));
            if ($from->office_id) {
                abort_unless($request->user()->canAccessOffice((int) $from->office_id), 403);
            }

            $returned = $this->inventory->returnToWarehouse(
                $from,
                $to,
                $data['items'],
                $request->user()->id,
                $data['reason'] ?? null,
            );
            StockJournal::record('create', $returned, [], $data);

            return $this->created($returned);
        }

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
        StockJournal::record('create', $return, [], $data);

        $autoAccept = array_key_exists('auto_accept', $data) ? (bool) $data['auto_accept'] : false;
        if ($autoAccept && $return->status === 'requested') {
            $return = $this->inventory->acceptReturn($return, $request->user()->id);
            StockJournal::record('update', $return, [], ['status' => $return->status, 'auto_accept' => true]);
        }

        return $this->created($return);
    }

    public function acceptReturn(Request $request, KamsStockReturn $stockReturn): JsonResponse
    {
        $this->assertCompany($request, (int) $stockReturn->company_id);
        $accepted = $this->inventory->acceptReturn($stockReturn, $request->user()->id);
        StockJournal::record('update', $accepted, [], ['status' => $accepted->status]);

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
            if ($movement->type === 'transfer' && $document instanceof KamsStockTransfer && $document->status) {
                $movement->setAttribute('status', $document->status);
            }
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
        StockJournal::record('create', $adjustment, [], $data);

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
        StockJournal::record('update', $paid, [], $data);

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
        $date = $request->date('date')?->toDateString() ?: now()->toDateString();
        $officeId = $request->filled('office_id') ? (int) $request->integer('office_id') : null;
        if ($officeId) {
            abort_unless($request->user()->canAccessOffice($officeId), 403);
        }
        if (! $companyId) {
            return $this->ok([
                'date' => $date,
                'office_id' => $officeId,
                'sales' => [],
            ]);
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
        StockJournal::record('create', $closed, [], $data);

        return $this->ok($closed);
    }

    public function journal(Request $request): JsonResponse
    {
        $types = [
            KamsStockAssignment::class,
            KamsStockTransfer::class,
            KamsStockReceipt::class,
            KamsStockReturn::class,
            KamsStockAdjustment::class,
            KamsStockSale::class,
            KamsProduct::class,
            KamsSellerCommission::class,
            KamsSavingsEntry::class,
            'system',
        ];

        $companyId = $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);
        $query = KamsStockJournalEntry::query()
            ->with('user:id,name,email')
            ->whereIn('auditable_type', $types)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->latest();

        if ($officeState['office_ids'] !== []) {
            $officeIds = $officeState['office_ids'];
            $assignmentIds = KamsStockAssignment::query()->whereIn('office_id', $officeIds)->pluck('id');
            $query->where(function ($inner) use ($officeIds, $assignmentIds) {
                $inner->whereIn('office_id', $officeIds)
                    ->orWhereIn('new_values->office_id', $officeIds)
                    ->orWhereIn('old_values->office_id', $officeIds)
                    ->orWhere(function ($assignment) use ($assignmentIds) {
                        $assignment->where('auditable_type', KamsStockAssignment::class)
                            ->whereIn('auditable_id', $assignmentIds);
                    })
                    ->orWhere(function ($savings) use ($assignmentIds) {
                        $savings->where('auditable_type', KamsSavingsEntry::class)
                            ->whereIn('new_values->assignment_id', $assignmentIds);
                    });
            });
        }

        if ($action = $request->string('action')->toString()) {
            $query->where('action', $action);
        }

        if ($from = $request->string('from')->toString()) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->string('to')->toString()) {
            $query->whereDate('created_at', '<=', $to);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('auditable_type', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                    ->orWhere('new_values', 'like', "%{$search}%")
                    ->orWhere('old_values', 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 40);
        $paginator = $query->paginate($perPage);
        $logs = $paginator->getCollection();
        $lookups = $this->journalLookups($logs);
        $priors = $this->journalPriorValues($logs);
        $paginator->setCollection(
            $logs->map(fn (KamsStockJournalEntry $log) => $this->presentJournalEntry($log, $lookups, $priors))
        );

        return $this->paginated($paginator);
    }

    public function destroyJournal(Request $request, KamsStockJournalEntry $entry): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        abort_unless(! $companyId || (int) $entry->company_id === (int) $companyId, 403);

        if ($request->user()->isOfficeScoped()) {
            $officeIds = $request->user()->accessibleOfficeIds() ?: [0];
            abort_unless(
                $entry->office_id === null || in_array((int) $entry->office_id, $officeIds, true),
                403,
            );
        }

        $snapshot = [
            'id' => $entry->id,
            'company_id' => $entry->company_id,
            'office_id' => $entry->office_id,
            'action' => $entry->action,
            'auditable_type' => $entry->auditable_type,
            'auditable_id' => $entry->auditable_id,
        ];
        $entry->delete();

        return $this->ok(['deleted' => true, ...$snapshot]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, KamsStockJournalEntry>  $logs
     * @return array{
     *     assignments: \Illuminate\Support\Collection<int|string, string>,
     *     transfers: \Illuminate\Support\Collection<int|string, string>,
     *     receipts: \Illuminate\Support\Collection<int|string, string>,
     *     returns: \Illuminate\Support\Collection<int|string, string>,
     *     sales: \Illuminate\Support\Collection<int|string, string>,
     *     products: \Illuminate\Support\Collection<int|string, string>,
     *     depots: \Illuminate\Support\Collection<int|string, string>,
     *     members: \Illuminate\Support\Collection<int|string, string>,
     *     offices: \Illuminate\Support\Collection<int|string, string>
     * }
     */
    private function journalLookups($logs): array
    {
        $idsByEntity = [
            'KamsStockAssignment' => [],
            'KamsStockTransfer' => [],
            'KamsStockReceipt' => [],
            'KamsStockReturn' => [],
            'KamsStockSale' => [],
            'KamsProduct' => [],
            'KamsDepot' => [],
        ];
        $memberIds = [];
        $depotIds = [];
        $officeIds = [];

        foreach ($logs as $log) {
            $entity = class_basename($log->auditable_type);
            $new = is_array($log->new_values) ? $log->new_values : [];
            $old = is_array($log->old_values) ? $log->old_values : [];

            if ($log->auditable_id && isset($idsByEntity[$entity])) {
                $idsByEntity[$entity][] = (int) $log->auditable_id;
            }

            foreach ([$old, $new] as $payload) {
                if (! empty($payload['member_id'])) {
                    $memberIds[] = (int) $payload['member_id'];
                }
                foreach (['from_depot_id', 'to_depot_id', 'depot_id', 'warehouse_id'] as $key) {
                    if (! empty($payload[$key])) {
                        $depotIds[] = (int) $payload[$key];
                    }
                }
                foreach ($payload['items'] ?? [] as $item) {
                    if (is_array($item) && ! empty($item['product_id'])) {
                        $idsByEntity['KamsProduct'][] = (int) $item['product_id'];
                    }
                }
            }
            foreach ([$new['office_id'] ?? null, $old['office_id'] ?? null] as $officeId) {
                if ($officeId) {
                    $officeIds[] = (int) $officeId;
                }
            }
        }

        return [
            'assignments' => KamsStockAssignment::query()
                ->whereIn('id', array_values(array_unique($idsByEntity['KamsStockAssignment'])))
                ->pluck('reference', 'id'),
            'transfers' => KamsStockTransfer::query()
                ->whereIn('id', array_values(array_unique($idsByEntity['KamsStockTransfer'])))
                ->pluck('reference', 'id'),
            'receipts' => KamsStockReceipt::query()
                ->whereIn('id', array_values(array_unique($idsByEntity['KamsStockReceipt'])))
                ->pluck('reference', 'id'),
            'returns' => KamsStockReturn::query()
                ->whereIn('id', array_values(array_unique($idsByEntity['KamsStockReturn'])))
                ->pluck('reference', 'id'),
            'sales' => KamsStockSale::query()
                ->whereIn('id', array_values(array_unique($idsByEntity['KamsStockSale'])))
                ->pluck('reference', 'id'),
            'products' => KamsProduct::query()
                ->whereIn('id', array_values(array_unique($idsByEntity['KamsProduct'])))
                ->pluck('name', 'id'),
            'depots' => KamsDepot::query()
                ->whereIn('id', array_values(array_unique([
                    ...$idsByEntity['KamsDepot'],
                    ...$depotIds,
                ])))
                ->pluck('name', 'id'),
            'members' => KamsMember::query()
                ->whereIn('id', array_values(array_unique($memberIds)))
                ->get(['id', 'full_name', 'member_code'])
                ->mapWithKeys(fn (KamsMember $member) => [
                    $member->id => trim(($member->full_name ?? '').' · '.($member->member_code ?? '')),
                ]),
            'offices' => \App\Models\Office::query()
                ->whereIn('id', array_values(array_unique($officeIds)))
                ->pluck('name', 'id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $lookups
     * @return array<string, mixed>
     */
    /**
     * @param  \Illuminate\Support\Collection<int, KamsStockJournalEntry>  $logs
     * @return array<string, array<string, mixed>>
     */
    private function journalPriorValues($logs): array
    {
        $types = $logs->pluck('auditable_type')->filter()->unique()->values();
        $ids = $logs->pluck('auditable_id')->filter()->unique()->values();
        if ($types->isEmpty() || $ids->isEmpty()) {
            return [];
        }

        $history = KamsStockJournalEntry::query()
            ->whereIn('auditable_type', $types)
            ->whereIn('auditable_id', $ids)
            ->orderBy('id')
            ->get(['id', 'auditable_type', 'auditable_id', 'new_values']);

        $latest = [];
        $priors = [];
        foreach ($history as $row) {
            $key = $row->auditable_type.'#'.$row->auditable_id;
            $priors[$key.'@'.$row->id] = $latest[$key] ?? [];
            $values = is_array($row->new_values) ? $row->new_values : [];
            $latest[$key] = array_merge($latest[$key] ?? [], $values);
        }

        return $priors;
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentAuditSnapshot(KamsStockAssignment $assignment, bool $settlement = false, bool $full = false): array
    {
        $assignment->loadMissing('items');

        $snapshot = [
            'collected_amount' => $assignment->collected_amount,
            'items' => $assignment->items->map(function ($item) use ($settlement) {
                $line = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'quantity' => (int) $item->quantity_out,
                ];
                if ($settlement) {
                    $line['quantity_returned'] = (int) $item->quantity_returned;
                    $line['quantity_lost'] = (int) $item->quantity_lost;
                }

                return $line;
            })->values()->all(),
        ];

        if ($full) {
            $snapshot = array_merge([
                'reference' => $assignment->reference,
                'status' => $assignment->status,
                'company_id' => (int) $assignment->company_id,
                'office_id' => (int) $assignment->office_id,
                'member_id' => (int) $assignment->member_id,
                'depot_id' => (int) $assignment->depot_id,
                'assigned_at' => optional($assignment->assigned_at)?->toIso8601String(),
            ], $snapshot);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $lookups
     * @param  array<string, array<string, mixed>>  $priors
     * @return array<string, mixed>
     */
    private function presentJournalEntry(KamsStockJournalEntry $log, array $lookups = [], array $priors = []): array
    {
        $entity = class_basename($log->auditable_type);
        $new = is_array($log->new_values) ? $log->new_values : [];
        $old = is_array($log->old_values) ? $log->old_values : [];
        if ($log->action !== 'create') {
            $prior = $priors[$log->auditable_type.'#'.$log->auditable_id.'@'.$log->id] ?? [];
            foreach ($new as $key => $value) {
                if (! array_key_exists($key, $old) && array_key_exists($key, $prior)) {
                    $old[$key] = $prior[$key];
                }
            }
            if ($old === [] && $prior !== []) {
                $old = $prior;
            }
        }
        $event = $this->journalEventKey($log->action, $entity, $old, $new);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'event' => $event,
            'entity' => $entity,
            'auditable_id' => $log->auditable_id,
            'subject' => $this->journalSubject($entity, $old, $new, $log->auditable_id, $lookups),
            'details' => $this->journalDetails($entity, $old, $new, $lookups),
            'changes' => $this->journalChanges($old, $new, $lookups),
            'created_at' => $log->created_at?->toIso8601String(),
            'ip_address' => $log->ip_address,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
                'email' => $log->user->email,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function journalEventKey(string $action, string $entity, array $old, array $new): string
    {
        if ($entity === 'KamsStockAssignment') {
            if ($action === 'create') {
                return 'assignment_create';
            }
            if ($action === 'delete') {
                return 'assignment_delete';
            }
            if (array_key_exists('quantity_returned', data_get($new, 'items.0', []))
                || array_key_exists('collected_amount', $new) && array_key_exists('items', $new)
                    && collect($new['items'] ?? [])->contains(fn ($item) => is_array($item) && (
                        array_key_exists('quantity_returned', $item) || array_key_exists('quantity_lost', $item)
                    ))) {
                return 'assignment_settle';
            }
            if (collect($new['items'] ?? [])->contains(fn ($item) => is_array($item) && array_key_exists('product_id', $item))) {
                return 'assignment_add_items';
            }
            if (collect($new['items'] ?? [])->contains(fn ($item) => is_array($item) && (
                array_key_exists('quantity', $item) && (int) ($item['quantity'] ?? -1) === 0
            ))) {
                return 'assignment_remove_product';
            }

            return 'assignment_update';
        }

        if ($entity === 'KamsStockTransfer') {
            if (($new['status'] ?? null) === 'received' || ($new['auto_receive'] ?? false)) {
                return 'transfer_receive';
            }

            return $action === 'create' ? 'transfer_create' : 'transfer_update';
        }

        if ($entity === 'KamsStockReceipt') {
            if (($new['status'] ?? null) === 'received') {
                return 'receipt_validate';
            }
            if (($new['status'] ?? null) === 'cancelled') {
                return 'receipt_cancel';
            }

            return $action === 'create' ? 'receipt_create' : 'receipt_update';
        }

        if ($entity === 'KamsStockReturn') {
            if (array_key_exists('status', $new) || ($new['auto_accept'] ?? false)) {
                return 'return_accept';
            }

            return $action === 'create' ? 'return_create' : 'return_update';
        }

        if ($entity === 'KamsStockAdjustment') {
            return $action === 'create' ? 'adjustment_create' : 'adjustment_update';
        }

        if ($entity === 'KamsProduct') {
            if (array_key_exists('warehouse_ids', $new) || array_key_exists('depot_ids', $new)
                || array_key_exists('assign_all_warehouses', $new) || array_key_exists('assign_all_depots', $new)) {
                return $action === 'create' ? 'product_create_assign' : 'product_reassign';
            }

            return match ($action) {
                'create' => 'product_create',
                'delete' => 'product_delete',
                default => 'product_update',
            };
        }

        if ($entity === 'KamsDepot') {
            if (array_key_exists('office_id', $new) || array_key_exists('office_id', $old)) {
                return $action === 'create' ? 'depot_create' : 'depot_office_change';
            }
            $kind = $new['kind'] ?? $old['kind'] ?? null;

            return match ($action) {
                'create' => ($kind === 'warehouse' ? 'warehouse_create' : 'depot_create'),
                'delete' => ($kind === 'warehouse' ? 'warehouse_delete' : 'depot_delete'),
                default => ($kind === 'warehouse' ? 'warehouse_update' : 'depot_update'),
            };
        }

        if ($entity === 'KamsDailySettlement') {
            return 'daily_settlement_close';
        }

        if ($entity === 'KamsSellerCommission') {
            return 'commission_pay';
        }

        if ($entity === 'KamsSavingsEntry') {
            return ($new['reason'] ?? null) === 'day_closing_surplus' ? 'surplus_deposit' : 'savings_entry';
        }

        if ($entity === 'KamsStockSale') {
            return $action === 'create' ? 'sale_create' : 'sale_update';
        }

        return $action;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $lookups
     */
    private function journalSubject(string $entity, array $old, array $new, mixed $auditableId, array $lookups = []): string
    {
        foreach (['reference', 'name', 'sku', 'code'] as $key) {
            $value = $new[$key] ?? $old[$key] ?? null;
            if (is_string($value) && $value !== '' && ! preg_match('/^#\d+$/', $value)) {
                return $value;
            }
        }

        if (! $auditableId) {
            return '';
        }

        $map = match ($entity) {
            'KamsStockAssignment' => $lookups['assignments'] ?? null,
            'KamsStockTransfer' => $lookups['transfers'] ?? null,
            'KamsStockReceipt' => $lookups['receipts'] ?? null,
            'KamsStockReturn' => $lookups['returns'] ?? null,
            'KamsStockSale' => $lookups['sales'] ?? null,
            'KamsProduct' => $lookups['products'] ?? null,
            'KamsDepot' => $lookups['depots'] ?? null,
            default => null,
        };

        $resolved = $map[(int) $auditableId] ?? null;

        return is_string($resolved) && $resolved !== '' ? $resolved : '';
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $lookups
     * @return list<string>
     */
    private function journalDetails(string $entity, array $old, array $new, array $lookups = []): array
    {
        $details = [];
        $depots = $lookups['depots'] ?? collect();
        $members = $lookups['members'] ?? collect();
        $offices = $lookups['offices'] ?? collect();

        if ($entity === 'KamsStockAssignment') {
            $source = $new !== [] ? $new : $old;
            $itemCount = count($source['items'] ?? []);
            if ($itemCount > 0) {
                $details[] = $itemCount.' item(s)';
            }
            if (array_key_exists('collected_amount', $source)) {
                $details[] = 'collected: '.$source['collected_amount'];
            }
            if (! empty($source['member_id'])) {
                $memberLabel = $members[(int) $source['member_id']] ?? null;
                if (is_string($memberLabel) && $memberLabel !== '') {
                    $details[] = $memberLabel;
                }
            }
            if (! empty($source['office_id'])) {
                $officeLabel = $offices[(int) $source['office_id']] ?? null;
                if (is_string($officeLabel) && $officeLabel !== '') {
                    $details[] = $officeLabel;
                }
            }
            if (! empty($source['status'])) {
                $details[] = (string) $source['status'];
            }
            $removed = collect($new['items'] ?? [])
                ->filter(fn ($item) => is_array($item) && array_key_exists('quantity', $item) && (int) $item['quantity'] === 0)
                ->count();
            if ($removed > 0) {
                $details[] = 'removed: '.$removed;
            }
        }

        if ($entity === 'KamsStockTransfer') {
            $from = $depots[(int) ($new['from_depot_id'] ?? 0)] ?? null;
            $to = $depots[(int) ($new['to_depot_id'] ?? 0)] ?? null;
            if ($from || $to) {
                $details[] = trim(($from ?? '?').' → '.($to ?? '?'));
            }
            if (($new['status'] ?? null) === 'received') {
                $details[] = 'received';
            }
            $itemCount = count($new['items'] ?? []);
            if ($itemCount > 0) {
                $details[] = $itemCount.' item(s)';
            }
        }

        if ($entity === 'KamsProduct') {
            foreach (['warehouse_ids', 'depot_ids'] as $key) {
                if (array_key_exists($key, $new) && is_array($new[$key])) {
                    $details[] = Str::of($key)->replace('_ids', '')->toString().': '.count($new[$key]);
                }
            }
            if (! empty($new['assign_all_warehouses'])) {
                $details[] = 'all warehouses';
            }
            if (! empty($new['assign_all_depots'])) {
                $details[] = 'all depots';
            }
        }

        if ($entity === 'KamsDepot') {
            $officeId = $new['office_id'] ?? null;
            $previousOfficeId = $old['office_id'] ?? null;
            if ($officeId) {
                $officeName = $offices[(int) $officeId] ?? null;
                if (is_string($officeName) && $officeName !== '') {
                    $details[] = 'office: '.$officeName;
                }
            }
            if ($previousOfficeId && (int) $previousOfficeId !== (int) $officeId) {
                $previousName = $offices[(int) $previousOfficeId] ?? null;
                if (is_string($previousName) && $previousName !== '') {
                    $details[] = 'from office: '.$previousName;
                }
            }
            if (! empty($new['kind'])) {
                $details[] = (string) $new['kind'];
            }
        }

        if ($entity === 'KamsSavingsEntry' && array_key_exists('amount', $new)) {
            $details[] = 'amount: '.$new['amount'];
        }

        if ($entity === 'KamsStockAdjustment' && array_key_exists('quantity', $new)) {
            $details[] = 'qty: '.$new['quantity'];
        }

        return array_values(array_filter($details, fn ($line) => is_string($line) && $line !== ''));
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $lookups
     * @return list<array{field: string, before: ?string, after: ?string}>
     */
    private function journalChanges(array $old, array $new, array $lookups): array
    {
        $skip = ['updated_at', 'created_at', 'deleted_at', 'password', 'remember_token'];
        $changes = [];

        foreach (array_values(array_unique([...array_keys($old), ...array_keys($new)])) as $key) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            if ($key === 'items') {
                array_push($changes, ...$this->journalItemChanges($old[$key] ?? null, $new[$key] ?? null, $lookups));
                continue;
            }
            $before = array_key_exists($key, $old) ? $this->journalDisplayValue($key, $old[$key], $lookups) : null;
            $after = array_key_exists($key, $new) ? $this->journalDisplayValue($key, $new[$key], $lookups) : null;
            if ($before === $after) {
                continue;
            }
            $changes[] = [
                'field' => $key,
                'before' => $before,
                'after' => $after,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $lookups
     * @return list<array{field: string, before: ?string, after: ?string}>
     */
    private function journalItemChanges(mixed $before, mixed $after, array $lookups): array
    {
        $beforeRows = $this->journalItemIndex(is_array($before) ? $before : []);
        $afterRows = $this->journalItemIndex(is_array($after) ? $after : []);
        $shared = array_intersect(array_keys($beforeRows), array_keys($afterRows));
        if ($shared === [] && $beforeRows !== [] && $afterRows !== []) {
            $beforeRows = array_values($beforeRows);
            $afterRows = array_values($afterRows);
            $keys = range(0, max(count($beforeRows), count($afterRows)) - 1);
        } else {
            $keys = array_values(array_unique([...array_keys($beforeRows), ...array_keys($afterRows)]));
        }

        $changes = [];
        foreach ($keys as $key) {
            $previous = $beforeRows[$key] ?? null;
            $current = $afterRows[$key] ?? null;
            $previousLine = is_array($previous) ? $this->journalItemLine($previous, $current, $lookups) : null;
            $currentLine = is_array($current) ? $this->journalItemLine($current, $previous, $lookups) : null;
            if ($previousLine === $currentLine) {
                continue;
            }
            $changes[] = [
                'field' => 'items',
                'before' => $previousLine,
                'after' => $currentLine,
            ];
        }

        return $changes;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int|string, array<string, mixed>>
     */
    private function journalItemIndex(array $items): array
    {
        $rows = [];
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = ! empty($item['id'])
                ? 'id:'.$item['id']
                : (! empty($item['product_id']) ? 'product:'.$item['product_id'] : 'index:'.$index);
            $rows[$key] = $item;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $sibling
     * @param  array<string, mixed>  $lookups
     */
    private function journalItemLine(array $item, ?array $sibling, array $lookups): ?string
    {
        $productId = $item['product_id'] ?? $sibling['product_id'] ?? null;
        $parts = [];
        if ($productId) {
            $name = ($lookups['products'] ?? collect())[(int) $productId] ?? null;
            if (is_string($name) && $name !== '') {
                $parts[] = $name;
            }
        }
        foreach (['quantity', 'quantity_returned', 'quantity_lost'] as $key) {
            if (! array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
                continue;
            }
            $parts[] = $key.': '.$item[$key];
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $lookups
     */
    private function journalDisplayValue(string $key, mixed $value, array $lookups): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            $lines = [];
            foreach ($value as $item) {
                if (! is_array($item)) {
                    $lines[] = (string) $item;
                    continue;
                }
                $parts = [];
                foreach ($item as $itemKey => $itemValue) {
                    if (in_array($itemKey, ['id', 'created_at', 'updated_at'], true) || $itemValue === null || $itemValue === '') {
                        continue;
                    }
                    $shown = $this->journalDisplayValue((string) $itemKey, $itemValue, $lookups);
                    if ($shown !== null) {
                        $parts[] = $itemKey.': '.$shown;
                    }
                }
                if ($parts !== []) {
                    $lines[] = implode(' · ', $parts);
                }
            }

            return $lines === [] ? null : implode("\n", $lines);
        }
        if (str_ends_with($key, '_id') && is_numeric($value)) {
            $resolved = match (true) {
                str_contains($key, 'member') => ($lookups['members'] ?? collect())[(int) $value] ?? null,
                str_contains($key, 'office') => ($lookups['offices'] ?? collect())[(int) $value] ?? null,
                str_contains($key, 'product') => ($lookups['products'] ?? collect())[(int) $value] ?? null,
                str_contains($key, 'depot') || str_contains($key, 'warehouse') => ($lookups['depots'] ?? collect())[(int) $value] ?? null,
                default => null,
            };
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Active products with available stock keyed by office depot (bureau).
     * When $officeIds is set (office-scoped user), only those offices — and only
     * products present in those office depots — are returned.
     *
     * @param  list<int>|null  $officeIds
     * @return Collection<int, array<string, mixed>>
     */
    private function lookupProductsWithOfficeStock(?int $companyId, ?array $officeIds = null): Collection
    {
        $products = KamsProduct::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'active')
            ->when($officeIds !== null, function ($q) use ($officeIds) {
                $q->whereHas('balances', fn ($balance) => $balance
                    ->where('condition', 'available')
                    ->where('quantity', '>', 0)
                    ->whereHas('depot', fn ($depot) => $depot
                        ->where('kind', 'depot')
                        ->whereIn('office_id', $officeIds ?: [0])));
            })
            ->with('officePrices:id,product_id,office_id,sale_price')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'sku', 'price', 'field_price', 'manager_price', 'company_price', 'cost', 'avg_cost', 'seller_share_percent', 'manager_share_percent', 'stock', 'tax_exempt', 'image_path']);

        $officeStock = [];
        if ($products->isNotEmpty()) {
            $balances = KamsStockBalance::query()
                ->whereIn('product_id', $products->pluck('id'))
                ->where('condition', 'available')
                ->where('quantity', '>', 0)
                ->whereHas('depot', fn ($q) => $q
                    ->where('kind', 'depot')
                    ->when($companyId, fn ($inner) => $inner->where('company_id', $companyId))
                    ->when($officeIds !== null, fn ($inner) => $inner->whereIn('office_id', $officeIds ?: [0])))
                ->with('depot:id,office_id,kind')
                ->get(['depot_id', 'product_id', 'quantity']);

            foreach ($balances as $balance) {
                $officeId = $balance->depot?->office_id;
                if (! $officeId) {
                    continue;
                }
                if ($officeIds !== null && ! in_array((int) $officeId, $officeIds, true)) {
                    continue;
                }
                $officeStock[(int) $balance->product_id][(int) $officeId] = (int) $balance->quantity;
            }
        }

        return $products->map(function (KamsProduct $product) use ($officeStock) {
            $row = $product->toArray();
            $row['office_stock'] = $officeStock[(int) $product->id] ?? [];

            return $row;
        })->values();
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsPurchaseOrder::query()
            ->with(['warehouse:id,name', 'supplier:id,name', 'items.product:id,name,sku'])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->latest('ordered_at')
            ->latest('id');

        $all = (clone $query)->with('items')->get();
        $ordered = round((float) $all->sum('total'), 2);
        $received = round($all->sum(fn (KamsPurchaseOrder $order) => $order->items->sum(
            fn ($item) => (int) $item->received_quantity * (float) $item->unit_cost
        )), 2);

        return $this->paginated($query->paginate($request->integer('per_page', 50)), 'OK', [
            'ordered_amount' => $ordered,
            'received_amount' => $received,
            'outstanding_amount' => round($ordered - $received, 2),
        ]);
    }

    public function storePurchaseOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:kams_depots,id'],
            'supplier_id' => ['nullable', 'exists:kams_clients,id'],
            'ordered_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $warehouse = KamsDepot::query()->where('kind', 'warehouse')->findOrFail($data['warehouse_id']);
        $this->assertCompany($request, (int) $warehouse->company_id);
        $supplier = isset($data['supplier_id'])
            ? KamsClient::query()->where('kind', 'supplier')->where('company_id', $warehouse->company_id)->find($data['supplier_id'])
            : null;

        $order = DB::transaction(function () use ($request, $data, $warehouse, $supplier) {
            $order = KamsPurchaseOrder::query()->create([
                'company_id' => $warehouse->company_id,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier?->id,
                'supplier_name' => $supplier?->name,
                'reference' => 'PO-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
                'status' => 'ordered',
                'ordered_at' => $data['ordered_at'],
                'notes' => $data['notes'] ?? null,
                'total' => 0,
                'created_by' => $request->user()?->id,
            ]);
            $total = 0.0;
            foreach ($data['items'] as $item) {
                $product = KamsProduct::query()->where('company_id', $warehouse->company_id)->findOrFail($item['product_id']);
                $line = round((int) $item['quantity'] * (float) $item['unit_cost'], 2);
                KamsPurchaseOrderItem::query()->create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => (int) $item['quantity'],
                    'received_quantity' => 0,
                    'unit_cost' => round((float) $item['unit_cost'], 2),
                    'line_total' => $line,
                ]);
                $total += $line;
            }
            $order->update(['total' => round($total, 2)]);
            $order->load(['warehouse:id,name', 'items.product:id,name']);
            StockJournal::record('create', $order, [], $this->purchaseOrderSnapshot($order));

            return $order;
        });

        return $this->created($order->load(['warehouse:id,name', 'supplier:id,name', 'items.product:id,name,sku']));
    }

    public function updatePurchaseOrder(Request $request, KamsPurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->assertCompany($request, (int) $purchaseOrder->company_id);
        abort_unless($purchaseOrder->status !== 'cancelled', 422, __('This order cannot be changed.'));

        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:kams_depots,id'],
            'supplier_id' => ['nullable', 'exists:kams_clients,id'],
            'ordered_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchaseOrder->load(['items.product:id,name', 'warehouse:id,name']);
        $received = $purchaseOrder->items->contains(fn ($item) => (int) $item->received_quantity > 0);
        $before = $this->purchaseOrderSnapshot($purchaseOrder);

        DB::transaction(function () use ($request, $data, $purchaseOrder, $received) {
            if ($received) {
                abort_unless((int) $data['warehouse_id'] === (int) $purchaseOrder->warehouse_id, 422, __('A received order cannot change warehouse.'));
                foreach ($data['items'] as $row) {
                    $item = $purchaseOrder->items->firstWhere('id', (int) ($row['id'] ?? 0));
                    abort_unless($item, 422, __('Received lines cannot be removed.'));
                    abort_unless((int) $row['product_id'] === (int) $item->product_id, 422, __('Received lines cannot change product.'));
                    abort_unless((int) $row['quantity'] >= (int) $item->received_quantity, 422, __('Quantity cannot be lower than what was already received.'));
                    abort_unless(round((float) $row['unit_cost'], 2) === round((float) $item->unit_cost, 2), 422, __('Received lines cannot change price.'));
                    $item->update([
                        'quantity' => (int) $row['quantity'],
                        'line_total' => round((int) $row['quantity'] * (float) $item->unit_cost, 2),
                    ]);
                }
                abort_unless(count($data['items']) === $purchaseOrder->items->count(), 422, __('Received lines cannot be removed.'));
            } else {
                $warehouse = KamsDepot::query()->where('kind', 'warehouse')->where('company_id', $purchaseOrder->company_id)->findOrFail($data['warehouse_id']);
                $supplier = ! empty($data['supplier_id'])
                    ? KamsClient::query()->where('kind', 'supplier')->where('company_id', $purchaseOrder->company_id)->find($data['supplier_id'])
                    : null;
                $purchaseOrder->items()->delete();
                $total = 0.0;
                foreach ($data['items'] as $row) {
                    $product = KamsProduct::query()->where('company_id', $purchaseOrder->company_id)->findOrFail($row['product_id']);
                    $line = round((int) $row['quantity'] * (float) $row['unit_cost'], 2);
                    KamsPurchaseOrderItem::query()->create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'product_id' => $product->id,
                        'quantity' => (int) $row['quantity'],
                        'received_quantity' => 0,
                        'unit_cost' => round((float) $row['unit_cost'], 2),
                        'line_total' => $line,
                    ]);
                    $total += $line;
                }
                $purchaseOrder->fill([
                    'warehouse_id' => $warehouse->id,
                    'supplier_id' => $supplier?->id,
                    'supplier_name' => $supplier?->name,
                    'total' => round($total, 2),
                ]);
            }

            $purchaseOrder->fill([
                'ordered_at' => $data['ordered_at'],
                'notes' => $data['notes'] ?? null,
            ]);
            if ($received) {
                $purchaseOrder->load('items');
                $purchaseOrder->total = round((float) $purchaseOrder->items->sum('line_total'), 2);
                $open = $purchaseOrder->items->contains(fn ($item) => (int) $item->received_quantity < (int) $item->quantity);
                $purchaseOrder->status = $open ? ($purchaseOrder->items->sum('received_quantity') > 0 ? 'partial' : 'ordered') : 'received';
            }
            $purchaseOrder->save();
            $purchaseOrder->load(['warehouse:id,name', 'items.product:id,name']);
            StockJournal::record('update', $purchaseOrder, $before, $this->purchaseOrderSnapshot($purchaseOrder));
        });

        return $this->ok($purchaseOrder->fresh(['warehouse:id,name', 'supplier:id,name', 'items.product:id,name,sku']));
    }

    public function destroyPurchaseOrder(Request $request, KamsPurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->assertCompany($request, (int) $purchaseOrder->company_id);
        $purchaseOrder->load(['items.product:id,name', 'warehouse:id,name']);
        abort_unless(
            $purchaseOrder->items->every(fn ($item) => (int) $item->received_quantity === 0),
            422,
            __('A received order cannot be deleted.'),
        );
        $before = $this->purchaseOrderSnapshot($purchaseOrder);
        StockJournal::record('delete', $purchaseOrder, $before, ['reference' => $purchaseOrder->reference, 'status' => 'deleted']);
        $purchaseOrder->delete();

        return $this->ok(['deleted' => true]);
    }

    public function purchaseOrderJournal(Request $request): JsonResponse
    {
        $companyId = $this->kamsCompanyId($request);
        $query = KamsStockJournalEntry::query()
            ->with('user:id,name')
            ->where('auditable_type', KamsPurchaseOrder::class)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when($request->filled('order_id'), fn ($q) => $q->where('auditable_id', $request->integer('order_id')))
            ->latest();

        $paginator = $query->paginate(min(max($request->integer('per_page', 50), 1), 100));
        $paginator->setCollection($paginator->getCollection()->map(fn (KamsStockJournalEntry $log) => $this->presentPurchaseJournal($log)));

        return $this->paginated($paginator);
    }

    public function receivePurchaseOrder(Request $request, KamsPurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->assertCompany($request, (int) $purchaseOrder->company_id);
        abort_unless(in_array($purchaseOrder->status, ['ordered', 'partial'], true), 422, __('This order cannot be received.'));

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
        ]);

        $purchaseOrder->load(['items.product', 'warehouse']);
        $lines = [];
        foreach ($data['items'] as $row) {
            $quantity = (int) $row['quantity'];
            if ($quantity < 1) {
                continue;
            }
            $item = $purchaseOrder->items->firstWhere('id', (int) $row['id']);
            abort_unless($item, 422, __('Unknown order line.'));
            $remaining = (int) $item->quantity - (int) $item->received_quantity;
            abort_unless($quantity <= $remaining, 422, __('Received quantity exceeds the order.'));
            $lines[] = ['item' => $item, 'quantity' => $quantity];
        }
        abort_unless($lines !== [], 422, __('Add at least one product.'));

        DB::transaction(function () use ($request, $purchaseOrder, $lines) {
            $receipt = $this->inventory->createReceipt(
                $purchaseOrder->warehouse,
                array_map(fn ($line) => [
                    'product_id' => $line['item']->product_id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => (float) $line['item']->unit_cost,
                ], $lines),
                $purchaseOrder->supplier_name,
                $request->user()?->id,
                $purchaseOrder->reference,
            );
            $this->inventory->validateReceipt($receipt, (int) $request->user()->id);
            foreach ($lines as $line) {
                $line['item']->increment('received_quantity', $line['quantity']);
            }
            $purchaseOrder->refresh()->load('items');
            $open = $purchaseOrder->items->contains(fn ($item) => (int) $item->received_quantity < (int) $item->quantity);
            $purchaseOrder->update(['status' => $open ? 'partial' : 'received']);
            $purchaseOrder->load(['warehouse:id,name', 'items.product:id,name']);
            StockJournal::record('receive', $purchaseOrder, [], array_merge($this->purchaseOrderSnapshot($purchaseOrder), [
                'received_now' => array_map(fn ($line) => [
                    'product' => $line['item']->product?->name,
                    'quantity' => $line['quantity'],
                    'unit_cost' => (float) $line['item']->unit_cost,
                ], $lines),
            ]));
        });

        return $this->ok($purchaseOrder->fresh(['warehouse:id,name', 'supplier:id,name', 'items.product:id,name,sku']));
    }

    public function cancelPurchaseOrder(Request $request, KamsPurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->assertCompany($request, (int) $purchaseOrder->company_id);
        $purchaseOrder->load('items');
        abort_unless(
            in_array($purchaseOrder->status, ['ordered', 'partial'], true)
            && $purchaseOrder->items->every(fn ($item) => (int) $item->received_quantity === 0),
            422,
            __('This order cannot be cancelled.'),
        );
        $before = $this->purchaseOrderSnapshot($purchaseOrder->load(['items.product:id,name', 'warehouse:id,name']));
        $purchaseOrder->update(['status' => 'cancelled']);
        $purchaseOrder->load(['warehouse:id,name', 'items.product:id,name']);
        StockJournal::record('cancel', $purchaseOrder, $before, $this->purchaseOrderSnapshot($purchaseOrder));

        return $this->ok($purchaseOrder->fresh(['warehouse:id,name', 'supplier:id,name', 'items.product:id,name,sku']));
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseOrderSnapshot(KamsPurchaseOrder $order): array
    {
        $order->loadMissing(['warehouse:id,name', 'items.product:id,name']);

        return [
            'reference' => $order->reference,
            'status' => $order->status,
            'supplier' => $order->supplier_name,
            'warehouse' => $order->warehouse?->name,
            'ordered_at' => optional($order->ordered_at)->toDateString(),
            'total' => (float) $order->total,
            'notes' => $order->notes,
            'items' => $order->items->map(fn (KamsPurchaseOrderItem $item) => [
                'product' => $item->product?->name,
                'quantity' => (int) $item->quantity,
                'received_quantity' => (int) $item->received_quantity,
                'unit_cost' => (float) $item->unit_cost,
                'line_total' => (float) $item->line_total,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPurchaseJournal(KamsStockJournalEntry $log): array
    {
        $old = is_array($log->old_values) ? $log->old_values : [];
        $new = is_array($log->new_values) ? $log->new_values : [];
        $changes = [];
        foreach (['status', 'supplier', 'warehouse', 'ordered_at', 'total', 'notes'] as $field) {
            $before = array_key_exists($field, $old) ? $old[$field] : null;
            $after = array_key_exists($field, $new) ? $new[$field] : null;
            if ($before != $after && ($before !== null || $after !== null)) {
                $changes[] = ['field' => $field, 'before' => $before, 'after' => $after];
            }
        }
        $oldItems = json_encode($old['items'] ?? []);
        $newItems = json_encode($new['items'] ?? []);
        if ($oldItems !== $newItems) {
            $changes[] = [
                'field' => 'items',
                'before' => $old['items'] ?? [],
                'after' => $new['items'] ?? [],
            ];
        }
        if (! empty($new['received_now'])) {
            $changes[] = ['field' => 'received_now', 'before' => null, 'after' => $new['received_now']];
        }

        return [
            'id' => $log->id,
            'action' => $log->action,
            'order_id' => $log->auditable_id,
            'reference' => $new['reference'] ?? $old['reference'] ?? null,
            'created_at' => $log->created_at,
            'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
            'changes' => $changes,
            'items' => $new['items'] ?? $old['items'] ?? [],
        ];
    }
}
