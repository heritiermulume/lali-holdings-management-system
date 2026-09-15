<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Core\Support\AuditLogger;
use App\Core\Support\TaxSettings;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Kams\Models\KamsCampaign;
use App\Modules\Kams\Models\KamsClient;
use App\Modules\Kams\Models\KamsOrder;
use App\Modules\Kams\Models\KamsOrderItem;
use App\Modules\Kams\Models\KamsProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KamsResourceController extends Controller
{
    use RespondsWithJson;

    public function clients(Request $request): JsonResponse
    {
        return $this->paginated($this->scoped(KamsClient::query(), $request)->latest()->paginate($request->integer('per_page', 15)));
    }

    public function storeClient(Request $request): JsonResponse
    {
        return $this->store(KamsClient::class, $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'segment' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive,prospect'],
            'notes' => ['nullable', 'string'],
        ]), $request);
    }

    public function updateClient(Request $request, KamsClient $client): JsonResponse
    {
        return $this->update($client, $request->validate([
            'name' => ['sometimes', 'string'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'segment' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,inactive,prospect'],
            'notes' => ['nullable', 'string'],
        ]), $request);
    }

    public function destroyClient(Request $request, KamsClient $client): JsonResponse
    {
        return $this->destroy($client, $request);
    }

    public function products(Request $request): JsonResponse
    {
        return $this->paginated($this->scoped(KamsProduct::query(), $request)->latest()->paginate($request->integer('per_page', 15)));
    }

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->store(KamsProduct::class, $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'sku' => ['nullable', 'string'],
            'name' => ['required', 'string'],
            'category' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'description' => ['nullable', 'string'],
        ]), $request);
    }

    public function updateProduct(Request $request, KamsProduct $product): JsonResponse
    {
        return $this->update($product, $request->validate([
            'sku' => ['nullable', 'string'],
            'name' => ['sometimes', 'string'],
            'category' => ['nullable', 'string'],
            'collection' => ['nullable', 'string'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:active,draft,archived'],
            'description' => ['nullable', 'string'],
        ]), $request);
    }

    public function destroyProduct(Request $request, KamsProduct $product): JsonResponse
    {
        return $this->destroy($product, $request);
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

        if ($search = $request->string('search')->toString()) {
            $table = $query->getModel()->getTable();
            $query->where(function ($q) use ($search, $table) {
                if ($table === 'kams_orders') {
                    $q->where('reference', 'like', "%{$search}%");
                } else {
                    $q->where('name', 'like', "%{$search}%");
                }
            });
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

    private function assertCompany(Request $request, int $companyId): void
    {
        abort_unless($request->user()->canAccessCompany($companyId), 403);
    }
}
