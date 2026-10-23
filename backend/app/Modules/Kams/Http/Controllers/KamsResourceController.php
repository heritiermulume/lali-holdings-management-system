<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Core\Support\TaxSettings;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsDepot;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsOrderItem;
use App\Modules\Kams\Models\KamsProduct;
use App\Modules\Kams\Models\KamsStockBalance;
use App\Modules\Kams\Services\PeoplePhotoStorage;
use App\Modules\Kams\Services\StockInventory;
use App\Modules\Kams\Services\StockJournal;
use App\Modules\Kams\Support\DomainOptions;
use App\Modules\Kams\Support\KamsDocumentRefs;
use App\Support\SettingsCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
            'options' => DomainOptions::all(),
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
                    // company_id + tax_exempt required for sale_price (catalog + country tax config)
                    'balances.product:id,company_id,name,sku,price,cost,avg_cost,field_price,manager_price,company_price,tax_exempt,image_path,stock',
                    'balances.product.company:id,country_id',
                    'balances.product.company.country:id,tax_rate,tax_name',
                    'balances.product.officePrices:id,product_id,office_id,sale_price',
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
        $user = $request->user();
        if ($data['kind'] === 'warehouse') {
            abort_unless(
                $user->hasPermission('kams.stock.warehouses.create') || $user->hasPermission('kams.products.create'),
                403
            );
        } else {
            abort_unless(
                $user->hasPermission('kams.stock.depots.create') || $user->hasPermission('kams.products.create'),
                403
            );
        }

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

        if ($depot->kind === 'warehouse') {
            abort_unless($this->canManageWarehouses($request), 403);
        } elseif ($depot->office_id) {
            abort_unless($request->user()->canAccessOffice((int) $depot->office_id), 403);
        }

        if ($depot->kind === 'warehouse') {
            abort_unless($request->user()->hasPermission('kams.stock.warehouses.update'), 403);
        } else {
            abort_unless(
                $request->user()->hasPermission('kams.stock.depots.update') || $request->user()->hasPermission('kams.products.update'),
                403
            );
        }

        $data = $request->validate([
            'office_id' => ['nullable', 'exists:offices,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'name' => ['sometimes', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($depot->kind === 'depot' && array_key_exists('office_id', $data) && $data['office_id'] !== null && $this->canChooseOffice($request)) {
            $office = $this->resolveClientOffice($request, $data['office_id']);
            abort_if(
                KamsDepot::query()
                    ->where('office_id', $office->id)
                    ->where('kind', 'depot')
                    ->whereKeyNot($depot->id)
                    ->exists(),
                422,
                __('This office already has a depot.')
            );
            $data['office_id'] = $office->id;
            $data['company_id'] = $office->company_id;
            $data['province_id'] = $office->province_id;
        } else {
            unset($data['office_id']);
        }

        return $this->update($depot, $data, $request);
    }

    public function destroyDepot(Request $request, KamsDepot $depot): JsonResponse
    {
        if ($depot->kind === 'warehouse') {
            abort_unless($this->canManageWarehouses($request), 403);
            abort_unless($request->user()->hasPermission('kams.stock.warehouses.delete'), 403);
        } else {
            abort_unless($request->user()->hasPermission('kams.stock.depots.delete'), 403);
            if ($depot->office_id) {
                abort_unless($request->user()->canAccessOffice((int) $depot->office_id), 403);
            }
        }

        return $this->destroy($depot, $request);
    }

    public function products(Request $request): JsonResponse
    {
        $query = $this->scoped(KamsProduct::query()->with(['balances.depot:id,name,kind,office_id', 'officePrices']), $request);
        $officeState = $this->resolveOfficeFilterState($request, $this->kamsCompanyId($request));
        $officeIds = $officeState['office_ids'];

        if ($officeIds !== []) {
            // Office-scoped / explicit filter: only products present in accessible office depots.
            if ($request->user()->isOfficeScoped() || $request->filled('office_id')) {
                $query->whereHas('balances', fn ($balance) => $balance
                    ->whereHas('depot', fn ($depot) => $depot
                        ->where('kind', 'depot')
                        ->whereIn('office_id', $officeIds)));
            }
            $query->with(['balances' => fn ($balance) => $balance
                ->whereHas('depot', fn ($depot) => $depot
                    ->where('kind', 'depot')
                    ->whereIn('office_id', $officeIds))
                ->with('depot:id,name,kind,office_id')]);
        }

        return $this->paginated($query->latest()->paginate($request->integer('per_page', 50)));
    }

    public function storeProduct(Request $request, StockInventory $inventory): JsonResponse
    {
        $this->normalizeOfficePrices($request);
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'sku' => ['nullable', 'string'],
            'name' => ['required', 'string'],
            'category' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'field_price' => ['nullable', 'numeric', 'min:0'],
            'company_price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['required', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'seller_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manager_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warehouse_id' => ['nullable', 'exists:kams_depots,id'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_warehouses' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'tax_exempt' => ['nullable', 'boolean'],
            'image' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'depot_ids' => ['nullable', 'array'],
            'depot_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_depots' => ['nullable', 'boolean'],
            'office_prices' => ['nullable', 'array'],
            'office_prices.*.office_id' => ['required', 'integer', 'exists:offices,id'],
            'office_prices.*.sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['tax_exempt'] = $request->boolean('tax_exempt');
        $initial = (int) ($data['stock'] ?? 0);
        $warehouseId = $data['warehouse_id'] ?? null;
        $warehouseIds = $data['warehouse_ids'] ?? null;
        $assignAllWarehouses = (bool) ($data['assign_all_warehouses'] ?? false);
        $depotIds = $data['depot_ids'] ?? null;
        $assignAll = (bool) ($data['assign_all_depots'] ?? false);
        $officePrices = $data['office_prices'] ?? null;
        unset(
            $data['stock'],
            $data['warehouse_id'],
            $data['warehouse_ids'],
            $data['assign_all_warehouses'],
            $data['depot_ids'],
            $data['assign_all_depots'],
            $data['office_prices'],
            $data['image'],
            $data['manager_price']
        );
        $sale = (float) ($data['field_price'] ?? $data['price']);
        $purchase = (float) ($data['company_price'] ?? $data['cost'] ?? 0);
        abort_unless(
            $sale >= $purchase,
            422,
            __('Sale price must be greater than or equal to purchase price.'),
        );
        $data['field_price'] = $sale;
        $data['price'] = $sale;
        $data['cost'] = $purchase;
        $data['company_price'] = $purchase;
        $data['manager_price'] = $purchase;
        $data['avg_cost'] = $purchase;
        $data['seller_share_percent'] = $data['seller_share_percent'] ?? 0;
        $data['manager_share_percent'] = $data['manager_share_percent'] ?? 20;
        $data['stock'] = 0;
        $data['status'] = $data['status'] ?? 'active';

        if ($path = $this->storeProductImage($request)) {
            $data['image_path'] = $path;
        }

        $this->assertCompany($request, (int) $data['company_id']);
        $product = KamsProduct::query()->create($data);
        StockJournal::record('create', $product, [], $data);

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

        if (is_array($officePrices)) {
            $this->syncOfficePrices($product, $officePrices, $purchase);
        }

        return $this->created($product->fresh(['balances.depot', 'officePrices']));
    }

    public function updateProduct(Request $request, KamsProduct $product, StockInventory $inventory): JsonResponse
    {
        $this->assertCompany($request, (int) $product->company_id);
        $this->normalizeOfficePrices($request);

        $data = $request->validate([
            'sku' => ['nullable', 'string'],
            'name' => ['sometimes', 'string'],
            'category' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'field_price' => ['nullable', 'numeric', 'min:0'],
            'company_price' => ['nullable', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'seller_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manager_share_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'tax_exempt' => ['nullable', 'boolean'],
            'image' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'remove_image' => ['nullable', 'boolean'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_warehouses' => ['nullable', 'boolean'],
            'depot_ids' => ['nullable', 'array'],
            'depot_ids.*' => ['integer', 'exists:kams_depots,id'],
            'assign_all_depots' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'warehouse_id' => ['nullable', 'exists:kams_depots,id'],
            'office_prices' => ['nullable', 'array'],
            'office_prices.*.office_id' => ['required', 'integer', 'exists:offices,id'],
            'office_prices.*.sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($request->has('tax_exempt')) {
            $data['tax_exempt'] = $request->boolean('tax_exempt');
        }

        $warehouseIds = array_key_exists('warehouse_ids', $data) ? $data['warehouse_ids'] : null;
        $assignAllWarehouses = array_key_exists('assign_all_warehouses', $data) ? (bool) $data['assign_all_warehouses'] : null;
        $depotIds = array_key_exists('depot_ids', $data) ? $data['depot_ids'] : null;
        $assignAll = array_key_exists('assign_all_depots', $data) ? (bool) $data['assign_all_depots'] : null;
        $removeImage = (bool) ($data['remove_image'] ?? false);
        $officePrices = array_key_exists('office_prices', $data) ? $data['office_prices'] : null;
        $targetStock = array_key_exists('stock', $data) ? (int) $data['stock'] : null;
        $stockWarehouseId = $data['warehouse_id'] ?? null;
        unset(
            $data['warehouse_ids'],
            $data['assign_all_warehouses'],
            $data['depot_ids'],
            $data['assign_all_depots'],
            $data['office_prices'],
            $data['stock'],
            $data['warehouse_id'],
            $data['image'],
            $data['remove_image'],
            $data['manager_price']
        );

        $sale = (float) ($data['field_price'] ?? $data['price'] ?? $product->field_price ?? $product->price);
        $purchase = (float) ($data['company_price'] ?? $data['cost'] ?? $product->company_price ?? $product->cost);
        if ($request->has('field_price') || $request->has('price') || array_key_exists('company_price', $data) || array_key_exists('cost', $data)) {
            abort_unless(
                $sale >= $purchase,
                422,
                __('Sale price must be greater than or equal to purchase price.'),
            );
            $data['field_price'] = $sale;
            $data['price'] = $sale;
            $data['company_price'] = $purchase;
            $data['cost'] = $purchase;
            $data['manager_price'] = $purchase;
            // Refresh WAC only when there is no on-hand stock (no inventory history to protect).
            $onHand = (int) $product->balances()->sum('quantity');
            if ($onHand <= 0 || (float) $product->avg_cost <= 0) {
                $data['avg_cost'] = $purchase;
            }
        }

        if ($path = $this->storeProductImage($request, $product->image_path)) {
            $data['image_path'] = $path;
        } elseif ($removeImage && $product->image_path) {
            app(PeoplePhotoStorage::class)->delete($product->image_path);
            $data['image_path'] = null;
        }

        $product->fill($data)->save();
        StockJournal::record('update', $product, [], $data);

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

        if (is_array($officePrices)) {
            $this->syncOfficePrices($product, $officePrices, $purchase);
        }

        if ($targetStock !== null) {
            $this->applyProductStockTarget($inventory, $request, $product, $targetStock, $stockWarehouseId ? (int) $stockWarehouseId : null);
        }

        return $this->ok($product->fresh(['balances.depot', 'officePrices']));
    }

    public function updateOfficePrice(Request $request, KamsProduct $product): JsonResponse
    {
        $this->assertCompany($request, (int) $product->company_id);
        $data = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $officeId = (int) $data['office_id'];
        $raw = $data['sale_price'] ?? null;
        if ($raw === null || $raw === '') {
            $product->officePrices()->where('office_id', $officeId)->delete();
        } else {
            $price = round((float) $raw, 2);
            $purchase = (float) ($product->company_price ?? $product->cost ?? 0);
            abort_unless($price >= $purchase, 422, __('Sale price must be greater than or equal to purchase price.'));
            $product->officePrices()->updateOrCreate(
                ['office_id' => $officeId],
                ['sale_price' => $price],
            );
        }

        return $this->ok($product->fresh('officePrices'));
    }

    private function normalizeOfficePrices(Request $request): void
    {
        if (! is_array($request->input('office_prices'))) {
            return;
        }

        $request->merge([
            'office_prices' => collect($request->input('office_prices'))->map(function ($row) {
                $price = is_array($row) ? ($row['sale_price'] ?? null) : null;

                return [
                    'office_id' => is_array($row) ? ($row['office_id'] ?? null) : null,
                    'sale_price' => $price === '' ? null : $price,
                ];
            })->all(),
        ]);
    }

    /**
     * @param  list<array{office_id:int, sale_price?:float|string|null}>  $rows
     */
    private function syncOfficePrices(KamsProduct $product, array $rows, float $purchase): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $officeId = (int) ($row['office_id'] ?? 0);
            $raw = $row['sale_price'] ?? null;
            if ($officeId <= 0 || $raw === null || $raw === '') {
                continue;
            }
            $price = round((float) $raw, 2);
            abort_unless($price >= $purchase, 422, __('Sale price must be greater than or equal to purchase price.'));
            $product->officePrices()->updateOrCreate(
                ['office_id' => $officeId],
                ['sale_price' => $price],
            );
            $keep[] = $officeId;
        }

        $product->officePrices()->when(
            $keep !== [],
            fn ($query) => $query->whereNotIn('office_id', $keep),
        )->delete();
    }

    private function applyProductStockTarget(
        StockInventory $inventory,
        Request $request,
        KamsProduct $product,
        int $target,
        ?int $warehouseId,
    ): void {
        $product->refresh();
        $delta = $target - (int) $product->stock;
        if ($delta === 0) {
            return;
        }

        $warehouse = $warehouseId
            ? KamsDepot::query()->where('kind', 'warehouse')->where('company_id', $product->company_id)->find($warehouseId)
            : null;
        $warehouse ??= KamsDepot::query()->where('kind', 'warehouse')->where('company_id', $product->company_id)->orderBy('name')->first();
        abort_unless($warehouse, 422, __('Create a regional warehouse before receiving stock.'));

        if ($delta > 0) {
            $inventory->receive($warehouse, $product, $delta, $request->user()?->id, __('Product stock update'));
        } else {
            $inventory->adjust($warehouse, $product, 'lost', abs($delta), __('Product stock update'), $request->user()?->id);
        }
    }

    public function destroyProduct(Request $request, KamsProduct $product): JsonResponse
    {
        $this->assertCompany($request, (int) $product->company_id);
        if ($product->image_path) {
            app(PeoplePhotoStorage::class)->delete($product->image_path);
        }

        return $this->destroy($product, $request);
    }

    private function storeProductImage(Request $request, ?string $currentPath = null): ?string
    {
        $image = $request->file('image');
        if (! $image) {
            return null;
        }

        return app(PeoplePhotoStorage::class)->storeReplacing($currentPath, $image, 'kams/products');
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
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->assertCompany($request, (int) $data['company_id']);

        $normalizedItems = [];
        foreach ($data['items'] as $item) {
            $product = KamsProduct::query()->findOrFail($item['product_id']);
            abort_unless((int) $product->company_id === (int) $data['company_id'], 422, __('Product does not belong to this company.'));
            $unitPrice = array_key_exists('unit_price', $item) && $item['unit_price'] !== null
                ? (float) $item['unit_price']
                : $product->salePrice();
            $purchase = $product->inventoryUnitCost();
            abort_unless(
                $unitPrice >= $purchase,
                422,
                __('Sale price must be greater than or equal to purchase price.'),
            );
            if (! SettingsCatalog::bool('kams', 'allow_backorders')) {
                $available = (int) ($product->stock ?? 0);
                if ($available < (int) $item['quantity']) {
                    return response()->json([
                        'success' => false,
                        'message' => __('Insufficient stock for :product (available: :available).', [
                            'product' => $product->name,
                            'available' => $available,
                        ]),
                    ], 422);
                }
            }
            $normalizedItems[] = [
                'product_id' => (int) $product->id,
                'quantity' => (int) $item['quantity'],
                'unit_price' => $unitPrice,
            ];
        }

        $company = Company::query()->with('country')->find($data['company_id']);
        $subtotalItems = collect($normalizedItems)->sum(fn ($item) => $item['quantity'] * $item['unit_price']);
        $taxed = TaxSettings::apply((float) $subtotalItems, $company?->country);
        $terms = (int) SettingsCatalog::number('kams', 'default_payment_terms_days');
        $notes = $data['notes'] ?? null;
        if ($terms > 0) {
            $due = Carbon::parse($data['ordered_at'])->addDays($terms)->toDateString();
            $termsNote = 'Payment terms: '.$terms.' days (due '.$due.')';
            $notes = $notes ? $notes."\n".$termsNote : $termsNote;
        }

        $order = KamsOrder::query()->create([
            'company_id' => $data['company_id'],
            'client_id' => $data['client_id'],
            'reference' => KamsDocumentRefs::order(),
            'status' => $data['status'] ?? 'confirmed',
            'subtotal' => $taxed['subtotal'],
            'tax' => $taxed['tax'],
            'total' => $taxed['total'],
            'ordered_at' => $data['ordered_at'],
            'notes' => $notes,
        ]);

        foreach ($normalizedItems as $item) {
            KamsOrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        return $this->created($order->load(['client', 'items.product']));
    }

    public function updateOrder(Request $request, KamsOrder $order): JsonResponse
    {
        $this->assertCompany($request, (int) $order->company_id);

        $order->fill($request->validate([
            'status' => ['sometimes', 'in:draft,confirmed,fulfilled,cancelled'],
            'notes' => ['nullable', 'string'],
        ]))->save();

        return $this->ok($order->fresh(['client', 'items.product']));
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
        $companyId = $request->filled('company_id')
            ? (int) $request->company_id
            : $this->kamsCompanyId($request);
        $officeState = $this->resolveOfficeFilterState($request, $companyId);

        if ($table === 'kams_clients') {
            $kind = $request->filled('kind') ? $request->string('kind')->toString() : 'client';
            abort_unless(in_array($kind, ['client', 'supplier'], true), 422);
            $query->where('kind', $kind);
            if ($kind === 'client') {
                $this->applyOfficeIds($query, $officeState);
            }
        }

        if ($table === 'kams_depots') {
            $kind = $request->filled('kind') ? $request->string('kind')->toString() : null;
            if ($kind) {
                $query->where('kind', $kind);
            } else {
                $query->where('kind', '!=', 'seller');
            }
            // Regional warehouses are company-level (office_id null). Office-scoped managers
            // with warehouses.view must still see them; only office depots are office-filtered.
            if ($request->user()->isOfficeScoped() || $request->filled('office_id')) {
                $officeIds = $officeState['office_ids'] ?? [];
                if ($officeIds !== [] && $kind !== 'warehouse') {
                    $query->where(function ($q) use ($officeIds) {
                        $q->whereIn('office_id', $officeIds)->orWhere('kind', 'warehouse');
                    });
                }
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
        if ($model instanceof KamsProduct || $model instanceof KamsDepot) {
            StockJournal::record('create', $model, [], $data);
        }

        return $this->created($model);
    }

    private function update(Model $model, array $data, Request $request): JsonResponse
    {
        $this->assertCompany($request, (int) $model->company_id);
        $model->fill($data)->save();
        if ($model instanceof KamsProduct || $model instanceof KamsDepot) {
            StockJournal::record('update', $model, [], $data);
        }

        return $this->ok($model->fresh());
    }

    private function destroy(Model $model, Request $request): JsonResponse
    {
        $this->assertCompany($request, (int) $model->company_id);
        $snapshot = $model->toArray();
        $model->delete();
        if ($model instanceof KamsProduct || $model instanceof KamsDepot) {
            StockJournal::record('delete', $model, $snapshot);
        }

        return $this->ok(null, __('Deleted.'));
    }

    private function resolveSupplierWarehouses(Request $request, mixed $warehouseIds, bool $assignAll = false): Collection
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
