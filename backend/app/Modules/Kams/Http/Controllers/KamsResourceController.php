<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Core\Support\AuditLogger;
use App\Core\Support\TaxSettings;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsCampaign;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsOrderItem;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Services\StockInventory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KamsResourceController extends Controller
{
    use ResolvesKamsOffices;
    use RespondsWithJson;

    public function clientLookups(Request $request): JsonResponse
    {
        $offices = $this->kamsOffices($request)
            ->with('city:id,name')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'code', 'city_id']);

        $companyId = $this->kamsCompanyId($request) ?? $request->user()->primary_company_id;
        $warehouses = KamsDepot::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('kind', 'warehouse')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'code']);

        return $this->ok([
            'offices' => $offices,
            'warehouses' => $warehouses,
            'can_choose_office' => $this->canChooseOffice($request, $offices->count()),
            'can_choose_warehouse' => $warehouses->count() > 1 || $this->canManageWarehouses($request),
        ]);
    }

    public function clients(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->scoped(KamsClient::query()->with([
                'office:id,name,code,city_id',
                'office.city:id,name',
                'warehouses:id,name,code',
            ]), $request)
                ->latest()
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'office_id' => ['nullable', 'exists:offices,id'],
            'warehouse_ids' => ['nullable', 'array', 'min:1'],
            'warehouse_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_warehouses' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'in:client,supplier'],
            'name' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'segment' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive,prospect'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['kind'] = $data['kind'] ?? 'client';
        $warehouseIds = [];

        if ($data['kind'] === 'supplier') {
            $warehouses = $this->resolveSupplierWarehouses(
                $request,
                $data['warehouse_ids'] ?? null,
                (bool) ($data['assign_all_warehouses'] ?? false),
            );
            $warehouseIds = $warehouses->pluck('id')->all();
            $first = $warehouses->first();
            $data['company_id'] = $first->company_id;
            $data['office_id'] = $first->office_id ?: $this->kamsOffices($request)->value('id');
        } else {
            $office = $this->resolveClientOffice($request, $data['office_id'] ?? null);
            $data['office_id'] = $office->id;
            $data['company_id'] = $office->company_id;
        }

        unset($data['warehouse_ids'], $data['assign_all_warehouses']);
        $this->assertCompany($request, (int) $data['company_id']);
        $client = KamsClient::query()->create($data);
        if ($data['kind'] === 'supplier') {
            $client->warehouses()->sync($warehouseIds);
        }
        AuditLogger::record('create', $client, [], $data + ['warehouse_ids' => $warehouseIds]);

        return $this->created($client->load(['office', 'warehouses']));
    }

    public function updateClient(Request $request, KamsClient $client): JsonResponse
    {
        $this->assertCompany($request, (int) $client->company_id);

        $data = $request->validate([
            'office_id' => ['nullable', 'exists:offices,id'],
            'warehouse_ids' => ['nullable', 'array', 'min:1'],
            'warehouse_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_warehouses' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'in:client,supplier'],
            'name' => ['sometimes', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'segment' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive,prospect'],
            'notes' => ['nullable', 'string'],
        ]);

        $kind = $data['kind'] ?? $client->kind ?? 'client';
        $warehouseIds = null;

        if ($kind === 'supplier') {
            if (array_key_exists('warehouse_ids', $data) || $request->boolean('assign_all_warehouses') || $request->exists('warehouse_ids')) {
                $warehouses = $this->resolveSupplierWarehouses(
                    $request,
                    $data['warehouse_ids'] ?? $client->warehouses()->pluck('kams_depots.id')->all(),
                    (bool) ($data['assign_all_warehouses'] ?? false),
                );
                $warehouseIds = $warehouses->pluck('id')->all();
                $first = $warehouses->first();
                $data['company_id'] = $first->company_id;
                $data['office_id'] = $first->office_id
                    ?: ($client->office_id ?: $this->kamsOffices($request)->value('id'));
            }
        } elseif ($this->canChooseOffice($request)) {
            $office = $this->resolveClientOffice($request, $data['office_id'] ?? null);
            $data['office_id'] = $office->id;
            $data['company_id'] = $office->company_id;
            $warehouseIds = [];
        } else {
            unset($data['office_id']);
        }

        unset($data['warehouse_ids'], $data['assign_all_warehouses']);
        $client->fill($data)->save();
        if ($warehouseIds !== null) {
            $client->warehouses()->sync($warehouseIds);
        }
        AuditLogger::record('update', $client, [], $data + ['warehouse_ids' => $warehouseIds]);

        return $this->ok($client->fresh(['office', 'warehouses']));
    }

    public function destroyClient(Request $request, KamsClient $client): JsonResponse
    {
        return $this->destroy($client, $request);
    }

    public function depots(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->scoped(
                KamsDepot::query()->with([
                    'office:id,name,code,city_id',
                    'office.city:id,name',
                    'province:id,name,name_fr',
                    'balances.product:id,name,sku,price,cost,field_price,manager_price,company_price,description,image_path,stock',
                ]),
                $request
            )->latest()->paginate($request->integer('per_page', 50))
        );
    }

    public function storeDepot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'in:warehouse,depot'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'name' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);
        $data['kind'] = $data['kind'] ?? 'depot';
        $data['status'] = $data['status'] ?? 'active';

        if ($data['kind'] === 'warehouse') {
            abort_unless($this->canManageWarehouses($request), 403);
            $companyId = $this->kamsCompanyId($request) ?? $request->user()->primary_company_id;
            abort_unless($companyId, 422, __('No company is available for this account.'));
            $data['company_id'] = $companyId;
            $data['office_id'] = null;
        } else {
            $office = $this->resolveClientOffice($request, $data['office_id'] ?? null);
            abort_if(
                KamsDepot::query()->where('office_id', $office->id)->where('kind', 'depot')->exists(),
                422,
                __('This office already has a depot.')
            );
            $data['office_id'] = $office->id;
            $data['company_id'] = $office->company_id;
            $data['province_id'] = $data['province_id'] ?? $office->province_id;
        }

        return $this->store(KamsDepot::class, $data, $request);
    }

    public function updateDepot(Request $request, KamsDepot $depot): JsonResponse
    {
        $this->assertCompany($request, (int) $depot->company_id);

        $data = $request->validate([
            'office_id' => ['nullable', 'exists:offices,id'],
            'name' => ['sometimes', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($this->canChooseOffice($request)) {
            $office = $this->resolveClientOffice($request, $data['office_id'] ?? null);
            $data['office_id'] = $office->id;
            $data['company_id'] = $office->company_id;
        } else {
            unset($data['office_id']);
        }

        return $this->update($depot, $data, $request);
    }

    public function destroyDepot(Request $request, KamsDepot $depot): JsonResponse
    {
        return $this->destroy($depot, $request);
    }

    public function products(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->scoped(KamsProduct::query()->with(['balances.depot:id,name,kind,office_id']), $request)
                ->latest()
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function storeProduct(Request $request, StockInventory $inventory): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'sku' => ['nullable', 'string'],
            'name' => ['required', 'string'],
            'category' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'field_price' => ['nullable', 'numeric', 'min:0'],
            'manager_price' => ['nullable', 'numeric', 'min:0'],
            'company_price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'seller_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manager_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warehouse_id' => ['nullable', 'exists:kams_depots,id'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_warehouses' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'depot_ids' => ['nullable', 'array'],
            'depot_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_depots' => ['nullable', 'boolean'],
        ]);
        $initial = (int) ($data['stock'] ?? 0);
        $warehouseId = $data['warehouse_id'] ?? null;
        $warehouseIds = $data['warehouse_ids'] ?? null;
        $assignAllWarehouses = (bool) ($data['assign_all_warehouses'] ?? false);
        $depotIds = $data['depot_ids'] ?? null;
        $assignAll = (bool) ($data['assign_all_depots'] ?? false);
        unset(
            $data['stock'],
            $data['warehouse_id'],
            $data['warehouse_ids'],
            $data['assign_all_warehouses'],
            $data['depot_ids'],
            $data['assign_all_depots'],
            $data['image']
        );
        $data['cost'] = $data['company_price'] ?? $data['cost'] ?? round(((float) $data['price']) * 0.6, 2);
        $data['field_price'] = $data['field_price'] ?? $data['price'];
        $data['company_price'] = $data['company_price'] ?? $data['cost'];
        $data['manager_price'] = $data['manager_price'] ?? round((((float) $data['field_price']) + ((float) $data['company_price'])) / 2, 2);
        $data['price'] = $data['field_price'];
        $data['avg_cost'] = $data['cost'];
        $data['seller_share_percent'] = $data['seller_share_percent'] ?? 50;
        $data['stock'] = 0;
        $data['status'] = $data['status'] ?? 'active';

        if ($path = $this->storeProductImage($request)) {
            $data['image_path'] = $path;
        }

        $this->assertCompany($request, (int) $data['company_id']);
        $product = KamsProduct::query()->create($data);
        AuditLogger::record('create', $product, [], $data);

        if ($assignAllWarehouses || $warehouseIds !== null) {
            $this->syncProductWarehouses(
                $product,
                $assignAllWarehouses ? null : array_map('intval', $warehouseIds ?? []),
                $assignAllWarehouses
            );
        }

        if ($initial > 0) {
            $receiveWarehouseId = $warehouseId
                ?: (is_array($warehouseIds) && $warehouseIds !== [] ? (int) $warehouseIds[0] : null);
            $warehouse = $receiveWarehouseId
                ? KamsDepot::query()->where('kind', 'warehouse')->where('company_id', $product->company_id)->findOrFail($receiveWarehouseId)
                : KamsDepot::query()->where('kind', 'warehouse')->where('company_id', $product->company_id)->first();
            abort_unless($warehouse, 422, __('Create a regional warehouse before receiving stock.'));
            $inventory->receive($warehouse, $product, $initial, $request->user()->id);
        }

        if ($assignAll || $depotIds !== null) {
            $this->syncProductDepots($product, $assignAll ? null : array_map('intval', $depotIds ?? []), $assignAll);
        }

        return $this->created($product->fresh('balances.depot'));
    }

    public function updateProduct(Request $request, KamsProduct $product): JsonResponse
    {
        $this->assertCompany($request, (int) $product->company_id);

        $data = $request->validate([
            'sku' => ['nullable', 'string'],
            'name' => ['sometimes', 'string'],
            'category' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'field_price' => ['nullable', 'numeric', 'min:0'],
            'manager_price' => ['nullable', 'numeric', 'min:0'],
            'company_price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'seller_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manager_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'remove_image' => ['nullable', 'boolean'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_warehouses' => ['nullable', 'boolean'],
            'depot_ids' => ['nullable', 'array'],
            'depot_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_depots' => ['nullable', 'boolean'],
        ]);

        $warehouseIds = array_key_exists('warehouse_ids', $data) ? $data['warehouse_ids'] : null;
        $assignAllWarehouses = array_key_exists('assign_all_warehouses', $data) ? (bool) $data['assign_all_warehouses'] : null;
        $depotIds = array_key_exists('depot_ids', $data) ? $data['depot_ids'] : null;
        $assignAll = array_key_exists('assign_all_depots', $data) ? (bool) $data['assign_all_depots'] : null;
        $removeImage = (bool) ($data['remove_image'] ?? false);
        unset(
            $data['warehouse_ids'],
            $data['assign_all_warehouses'],
            $data['depot_ids'],
            $data['assign_all_depots'],
            $data['image'],
            $data['remove_image']
        );

        if ($request->has('field_price') || $request->has('price')) {
            $field = (float) ($data['field_price'] ?? $data['price'] ?? $product->field_price ?? $product->price);
            $data['field_price'] = $field;
            $data['price'] = $field;
        }
        if (array_key_exists('company_price', $data) || array_key_exists('cost', $data)) {
            $companyPrice = $data['company_price'] ?? $data['cost'] ?? $product->company_price ?? $product->cost;
            $data['company_price'] = $companyPrice;
            $data['cost'] = $companyPrice;
        }

        if ($path = $this->storeProductImage($request)) {
            if ($product->image_path) {
                Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $path;
        } elseif ($removeImage && $product->image_path) {
            Storage::disk('public')->delete($product->image_path);
            $data['image_path'] = null;
        }

        $product->fill($data)->save();
        AuditLogger::record('update', $product, [], $data);

        if ($assignAllWarehouses === true || $warehouseIds !== null) {
            $this->syncProductWarehouses(
                $product,
                $assignAllWarehouses ? null : array_map('intval', $warehouseIds ?? []),
                (bool) $assignAllWarehouses
            );
        }

        if ($assignAll === true || $depotIds !== null) {
            $this->syncProductDepots($product, $assignAll ? null : array_map('intval', $depotIds ?? []), (bool) $assignAll);
        }

        return $this->ok($product->fresh('balances.depot'));
    }

    public function destroyProduct(Request $request, KamsProduct $product): JsonResponse
    {
        $this->assertCompany($request, (int) $product->company_id);
        if ($product->image_path) {
            Storage::disk('public')->delete($product->image_path);
        }

        return $this->destroy($product, $request);
    }

    private function storeProductImage(Request $request): ?string
    {
        $image = $request->file('image');

        return $image?->store('kams/products', 'public');
    }

    /**
     * @param  list<int>|null  $locationIds
     */
    private function syncProductLocations(KamsProduct $product, string $kind, ?array $locationIds, bool $assignAll = false): void
    {
        $query = KamsDepot::query()
            ->where('company_id', $product->company_id)
            ->where('kind', $kind);

        if (! $assignAll) {
            $query->whereIn('id', $locationIds ?: [0]);
        }

        $locations = $query->get(['id', 'company_id']);
        $selectedIds = $locations->pluck('id')->all();

        foreach ($locations as $location) {
            KamsStockBalance::query()->firstOrCreate(
                ['depot_id' => $location->id, 'product_id' => $product->id, 'condition' => 'available'],
                ['company_id' => $product->company_id, 'quantity' => 0]
            );
        }

        $removable = KamsStockBalance::query()
            ->where('product_id', $product->id)
            ->where('condition', 'available')
            ->where('quantity', 0)
            ->whereHas('depot', fn ($q) => $q->where('kind', $kind));

        if ($selectedIds) {
            $removable->whereNotIn('depot_id', $selectedIds);
        }

        $removable->delete();
    }

    /**
     * @param  list<int>|null  $warehouseIds
     */
    private function syncProductWarehouses(KamsProduct $product, ?array $warehouseIds, bool $assignAll = false): void
    {
        $this->syncProductLocations($product, 'warehouse', $warehouseIds, $assignAll);
    }

    /**
     * @param  list<int>|null  $depotIds
     */
    private function syncProductDepots(KamsProduct $product, ?array $depotIds, bool $assignAll = false): void
    {
        $this->syncProductLocations($product, 'depot', $depotIds, $assignAll);
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->scoped(KamsOrder::query()->with(['client', 'items.product']), $request)
                ->latest()
                ->paginate($request->integer('per_page', 15))
        );
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'client_id' => ['required', 'exists:kams_clients,id'],
            'status' => ['nullable', 'in:draft,confirmed,fulfilled,cancelled'],
            'ordered_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:kams_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $this->assertCompany($request, (int) $data['company_id']);

        $company = Company::query()->with('country')->find($data['company_id']);
        $subtotalItems = collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_price']);
        $taxed = TaxSettings::apply((float) $subtotalItems, $company?->country);

        $order = KamsOrder::query()->create([
            'company_id' => $data['company_id'],
            'client_id' => $data['client_id'],
            'reference' => 'KA-'.now()->format('ymd').'-'.strtoupper(Str::random(4)),
            'status' => $data['status'] ?? 'confirmed',
            'subtotal' => $taxed['subtotal'],
            'tax' => $taxed['tax'],
            'total' => $taxed['total'],
            'ordered_at' => $data['ordered_at'],
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($data['items'] as $item) {
            KamsOrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        AuditLogger::record('create', $order, [], $order->toArray());

        return $this->created($order->load(['client', 'items.product']));
    }

    public function updateOrder(Request $request, KamsOrder $order): JsonResponse
    {
        $this->assertCompany($request, (int) $order->company_id);

        $order->fill($request->validate([
            'status' => ['sometimes', 'in:draft,confirmed,fulfilled,cancelled'],
            'notes' => ['nullable', 'string'],
        ]))->save();

        AuditLogger::record('update', $order);

        return $this->ok($order->fresh(['client', 'items.product']));
    }

    public function campaigns(Request $request): JsonResponse
    {
        return $this->paginated($this->scoped(KamsCampaign::query(), $request)->latest()->paginate($request->integer('per_page', 15)));
    }

    public function storeCampaign(Request $request): JsonResponse
    {
        return $this->store(KamsCampaign::class, $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string'],
            'channel' => ['nullable', 'string'],
            'status' => ['nullable', 'in:planned,active,completed,paused'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
        ]), $request);
    }

    public function updateCampaign(Request $request, KamsCampaign $campaign): JsonResponse
    {
        return $this->update($campaign, $request->validate([
            'name' => ['sometimes', 'string'],
            'channel' => ['nullable', 'string'],
            'status' => ['nullable', 'in:planned,active,completed,paused'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
        ]), $request);
    }

    public function destroyCampaign(Request $request, KamsCampaign $campaign): JsonResponse
    {
        return $this->destroy($campaign, $request);
    }

    private function scoped(Builder $query, Request $request): Builder
    {
        $companyIds = $request->user()->accessibleCompanyIds();

        if ($request->filled('company_id')) {
            $this->assertCompany($request, (int) $request->company_id);
            $query->where('company_id', $request->company_id);
        } elseif (! $request->user()->isGroupAdmin()) {
            $query->whereIn('company_id', $companyIds ?: [0]);
        }

        $table = $query->getModel()->getTable();

        if ($table === 'kams_clients' && $request->user()->isOfficeScoped()) {
            $query->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]);
        }

        if ($table === 'kams_clients') {
            $kind = $request->filled('kind') ? $request->string('kind')->toString() : 'client';
            abort_unless(in_array($kind, ['client', 'supplier'], true), 422);
            $query->where('kind', $kind);
        }

        if ($table === 'kams_depots') {
            if ($request->filled('kind')) {
                $query->where('kind', $request->string('kind')->toString());
            } else {
                $query->where('kind', '!=', 'seller');
            }
            if ($request->user()->isOfficeScoped()) {
                $query->where(function ($q) use ($request) {
                    $q->where('kind', 'warehouse')
                        ->orWhereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]);
                });
            }
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search, $table) {
                if ($table === 'kams_orders') {
                    $q->where('reference', 'like', "%{$search}%");
                } elseif ($table === 'kams_clients') {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('segment', 'like', "%{$search}%");
                } elseif ($table === 'kams_depots') {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                } else {
                    $q->where('name', 'like', "%{$search}%");
                }
            });
        }

        if (in_array($table, ['kams_clients', 'kams_depots'], true) && $request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return $query;
    }

    private function store(string $class, array $data, Request $request): JsonResponse
    {
        $this->assertCompany($request, (int) $data['company_id']);
        $model = $class::query()->create($data);
        AuditLogger::record('create', $model, [], $data);

        return $this->created($model);
    }

    private function update(Model $model, array $data, Request $request): JsonResponse
    {
        $this->assertCompany($request, (int) $model->company_id);
        $model->fill($data)->save();
        AuditLogger::record('update', $model, [], $data);

        return $this->ok($model->fresh());
    }

    private function destroy(Model $model, Request $request): JsonResponse
    {
        $this->assertCompany($request, (int) $model->company_id);
        $model->delete();
        AuditLogger::record('delete', $model);

        return $this->ok(null, __('Deleted.'));
    }

    private function resolveSupplierWarehouses(Request $request, mixed $warehouseIds, bool $assignAll = false): \Illuminate\Support\Collection
    {
        $companyId = $this->kamsCompanyId($request) ?? $request->user()->primary_company_id;
        $warehouses = KamsDepot::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('kind', 'warehouse')
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        abort_unless($warehouses->isNotEmpty(), 422, __('Create a regional warehouse before adding suppliers.'));

        if ($assignAll || $warehouses->count() === 1) {
            return $warehouses;
        }

        $ids = collect($warehouseIds ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
        abort_unless($ids->isNotEmpty(), 422, __('Select at least one warehouse.'));

        $selected = $warehouses->whereIn('id', $ids)->values();
        abort_unless($selected->count() === $ids->count(), 422, __('One or more selected warehouses are invalid.'));

        return $selected;
    }
}
