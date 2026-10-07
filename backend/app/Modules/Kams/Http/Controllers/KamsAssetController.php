<?php

namespace App\Modules\Kams\Http\Controllers;

use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Modules\Kams\Http\Controllers\Concerns\ResolvesKamsOffices;
use App\Modules\Kams\Models\KamsAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KamsAssetController extends Controller
{
    use ResolvesKamsOffices;
    use RespondsWithJson;

    /**
     * @return list<string>
     */
    public static function categories(): array
    {
        return ['furniture', 'equipment', 'vehicle', 'electronics', 'real_estate', 'other'];
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return ['active', 'maintenance', 'disposed'];
    }

    public function lookups(Request $request): JsonResponse
    {
        $offices = $this->kamsOffices($request)
            ->with('city:id,name')
            ->orderBy('name')
            ->get(['id', 'company_id', 'name', 'code', 'city_id']);

        return $this->ok([
            'offices' => $offices,
            'can_choose_office' => $this->canChooseOffice($request, $offices->count()),
            'categories' => self::categories(),
            'statuses' => self::statuses(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = KamsAsset::query()
            ->with(['office:id,name,code,city_id', 'office.city:id,name'])
            ->latest();

        $companyIds = $request->user()->accessibleCompanyIds();
        if ($request->filled('company_id')) {
            $this->assertCompany($request, (int) $request->company_id);
            $query->where('company_id', $request->company_id);
        } elseif (! $request->user()->isGroupAdmin()) {
            $query->whereIn('company_id', $companyIds ?: [0]);
        }

        if ($request->user()->isOfficeScoped()) {
            $query->whereIn('office_id', $request->user()->accessibleOfficeIds() ?: [0]);
        }

        if ($request->filled('office_id')) {
            $officeId = (int) $request->integer('office_id');
            $this->assertKamsOffice($request, $officeId);
            $query->where('office_id', $officeId);
        }

        $totalValue = (float) (clone $query)
            ->where('status', 'active')
            ->toBase()
            ->selectRaw('COALESCE(SUM(quantity * unit_value), 0) as value')
            ->value('value');

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            abort_unless(in_array($status, self::statuses(), true), 422);
            $query->where('status', $status);
        }

        if ($request->filled('category')) {
            $category = $request->string('category')->toString();
            abort_unless(in_array($category, self::categories(), true), 422);
            $query->where('category', $category);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        return $this->paginated($query->paginate($request->integer('per_page', 50)), extra: [
            'total_value' => round($totalValue, 2),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        unset($data['image'], $data['remove_image']);
        $office = $this->resolveClientOffice($request, $data['office_id'] ?? null);
        $this->assertCompany($request, (int) $office->company_id);

        $asset = KamsAsset::query()->create([
            'company_id' => $office->company_id,
            'office_id' => $office->id,
            'name' => $data['name'],
            'category' => $data['category'] ?? 'other',
            'quantity' => (int) ($data['quantity'] ?? 1),
            'unit_value' => round((float) ($data['unit_value'] ?? 0), 2),
            'acquired_on' => $data['acquired_on'] ?? null,
            'status' => $data['status'] ?? 'active',
            'notes' => $data['notes'] ?? null,
            'image_path' => $this->storeImage($request),
            'created_by' => $request->user()->id,
        ]);

        return $this->created($this->present($asset->load(['office:id,name,code,city_id', 'office.city:id,name'])));
    }

    public function update(Request $request, KamsAsset $asset): JsonResponse
    {
        $this->assertCompany($request, (int) $asset->company_id);
        if ($request->user()->isOfficeScoped()) {
            abort_unless(in_array((int) $asset->office_id, $request->user()->accessibleOfficeIds(), true), 403);
        }

        $data = $this->validated($request, true);
        $removeImage = $request->boolean('remove_image');
        unset($data['image'], $data['remove_image']);

        if ($this->canChooseOffice($request) && array_key_exists('office_id', $data)) {
            $office = $this->resolveClientOffice($request, $data['office_id'] ?? null);
            $data['office_id'] = $office->id;
            $data['company_id'] = $office->company_id;
        } else {
            unset($data['office_id']);
        }

        if (array_key_exists('quantity', $data)) {
            $data['quantity'] = (int) $data['quantity'];
        }
        if (array_key_exists('unit_value', $data)) {
            $data['unit_value'] = round((float) $data['unit_value'], 2);
        }

        if ($path = $this->storeImage($request)) {
            if ($asset->image_path) {
                Storage::disk('public')->delete($asset->image_path);
            }
            $data['image_path'] = $path;
        } elseif ($removeImage && $asset->image_path) {
            Storage::disk('public')->delete($asset->image_path);
            $data['image_path'] = null;
        }

        $asset->fill($data)->save();

        return $this->ok($this->present($asset->fresh(['office:id,name,code,city_id', 'office.city:id,name'])));
    }

    public function destroy(Request $request, KamsAsset $asset): JsonResponse
    {
        $this->assertCompany($request, (int) $asset->company_id);
        if ($request->user()->isOfficeScoped()) {
            abort_unless(in_array((int) $asset->office_id, $request->user()->accessibleOfficeIds(), true), 403);
        }

        if ($asset->image_path) {
            Storage::disk('public')->delete($asset->image_path);
        }

        $asset->delete();

        return $this->ok(null, __('Deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'office_id' => [$partial ? 'nullable' : 'nullable', 'exists:offices,id'],
            'name' => [$required, 'string', 'max:255'],
            'category' => ['nullable', 'in:'.implode(',', self::categories())],
            'quantity' => [$partial ? 'sometimes' : 'nullable', 'integer', 'min:1', 'max:1000000'],
            'unit_value' => [$partial ? 'sometimes' : 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'acquired_on' => ['nullable', 'date'],
            'status' => ['nullable', 'in:'.implode(',', self::statuses())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp'],
            'remove_image' => ['nullable', 'boolean'],
        ]);
    }

    private function storeImage(Request $request): ?string
    {
        $image = $request->file('image');

        return $image?->store('kams/assets', 'public');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(KamsAsset $asset): array
    {
        $payload = $asset->toArray();
        $payload['total_value'] = $asset->total_value;
        $payload['image_url'] = $asset->image_url;

        return $payload;
    }
}
