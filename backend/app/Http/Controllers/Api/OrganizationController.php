<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\CurrencySync;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Support\SettingsCatalog;
use App\Models\Activity;
use App\Models\City;
use App\Models\Commune;
use App\Models\Company;
use App\Models\Country;
use App\Models\Neighborhood;
use App\Models\Office;
use App\Models\Province;
use App\Models\SystemModule;
use App\Models\User;
use App\Modules\Kams\Models\KamsMember;
use App\Modules\Kams\Services\OfficeHierarchy;
use App\Modules\Kams\Services\RevenueRuleService;
use App\Models\Currency;
use App\Support\CurrencyCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    use RespondsWithJson;

    public function tree(): JsonResponse
    {
        return $this->ok(
            Country::query()
                ->with(['provinces.cities.communes.neighborhoods', 'cities.communes.neighborhoods', 'companies.offices'])
                ->orderBy('name')
                ->get()
        );
    }

    public function countries(Request $request): JsonResponse
    {
        return $this->list(Country::query()->withCount(['cities', 'companies']), $request);
    }

    public function storeCountry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
            'name_fr' => ['required', 'string'],
            'iso_code' => ['required', 'string', 'max:8', 'unique:countries,iso_code'],
            'phone_code' => ['nullable', 'string', 'max:8'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'currency_symbol' => ['nullable', 'string', 'max:8'],
            'tax_name' => ['nullable', 'string', 'max:64'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ]);
        if (! empty($data['currency_code']) && empty($data['currency_symbol'])) {
            $data['currency_symbol'] = Currency::findByCode((string) $data['currency_code'])?->symbol
                ?? CurrencyCatalog::find((string) $data['currency_code'])['symbol'];
        }
        $data['tax_name'] = filled($data['tax_name'] ?? null)
            ? (string) $data['tax_name']
            : SettingsCatalog::string('finance', 'tax_name');
        $data['tax_rate'] = array_key_exists('tax_rate', $data) && $data['tax_rate'] !== null
            ? $data['tax_rate']
            : SettingsCatalog::number('finance', 'default_tax_rate');
        $response = $this->storeModel(Country::class, $data);
        CurrencySync::ensureFromCountries();
        CurrencySync::propagateToCompanies();

        return $response;
    }

    public function updateCountry(Request $request, Country $country): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
            'iso_code' => ['sometimes', 'string', 'max:8', 'unique:countries,iso_code,'.$country->id],
            'phone_code' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string'],
            'currency_symbol' => ['nullable', 'string'],
            'fx_rate' => ['nullable', 'numeric', 'min:0'],
            'tax_name' => ['nullable', 'string', 'max:64'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ]);
        if (! empty($data['currency_code']) && empty($data['currency_symbol'])) {
            $data['currency_symbol'] = Currency::findByCode((string) $data['currency_code'])?->symbol
                ?? CurrencyCatalog::find((string) $data['currency_code'])['symbol'];
        }
        $response = $this->updateModel($country, $data);
        CurrencySync::ensureFromCountries();
        CurrencySync::propagateToCompanies((int) $country->id);

        return $response;
    }

    public function destroyCountry(Country $country): JsonResponse
    {
        $response = $this->destroyModel($country, ['provinces', 'cities', 'companies', 'offices']);
        CurrencySync::ensureFromCountries();

        return $response;
    }

    public function provinces(Request $request): JsonResponse
    {
        return $this->list(Province::query()->with('country')->when($request->country_id, fn ($q) => $q->where('country_id', $request->country_id)), $request);
    }

    public function storeProvince(Request $request): JsonResponse
    {
        return $this->storeModel(Province::class, $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'name' => ['required', 'string'],
            'name_fr' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateProvince(Request $request, Province $province): JsonResponse
    {
        return $this->updateModel($province, $request->validate([
            'country_id' => ['sometimes', 'exists:countries,id'],
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
            'code' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function destroyProvince(Province $province): JsonResponse
    {
        return $this->destroyModel($province, ['cities', 'offices']);
    }

    public function cities(Request $request): JsonResponse
    {
        return $this->list(City::query()->with(['country', 'province'])
            ->when($request->country_id, fn ($q) => $q->where('country_id', $request->country_id))
            ->when($request->province_id, fn ($q) => $q->where('province_id', $request->province_id)), $request);
    }

    public function storeCity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'province_id' => ['required', 'exists:provinces,id'],
            'name' => ['required', 'string'],
            'name_fr' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]);

        $provinceCountry = Province::query()->whereKey($data['province_id'])->value('country_id');
        abort_unless((int) $provinceCountry === (int) $data['country_id'], 422, __('The province must belong to the selected country.'));

        return $this->storeModel(City::class, $data);
    }

    public function updateCity(Request $request, City $city): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['sometimes', 'exists:countries,id'],
            'province_id' => ['sometimes', 'exists:provinces,id'],
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]);

        $countryId = (int) ($data['country_id'] ?? $city->country_id);
        $provinceId = (int) ($data['province_id'] ?? $city->province_id);
        if ($provinceId) {
            $provinceCountry = Province::query()->whereKey($provinceId)->value('country_id');
            abort_unless((int) $provinceCountry === $countryId, 422, __('The province must belong to the selected country.'));
        }

        return $this->updateModel($city, $data);
    }

    public function destroyCity(City $city): JsonResponse
    {
        return $this->destroyModel($city, ['communes', 'companies', 'offices']);
    }

    public function communes(Request $request): JsonResponse
    {
        return $this->list(Commune::query()->with(['city.province', 'city.country'])
            ->when($request->city_id, fn ($q) => $q->where('city_id', $request->city_id)), $request);
    }

    public function storeCommune(Request $request): JsonResponse
    {
        return $this->storeModel(Commune::class, $request->validate([
            'city_id' => ['required', 'exists:cities,id'],
            'name' => ['required', 'string'],
            'name_fr' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateCommune(Request $request, Commune $commune): JsonResponse
    {
        return $this->updateModel($commune, $request->validate([
            'city_id' => ['sometimes', 'exists:cities,id'],
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]));
    }

    public function destroyCommune(Commune $commune): JsonResponse
    {
        return $this->destroyModel($commune, ['neighborhoods', 'offices']);
    }

    public function neighborhoods(Request $request): JsonResponse
    {
        return $this->list(Neighborhood::query()->with(['commune.city'])
            ->when($request->commune_id, fn ($q) => $q->where('commune_id', $request->commune_id)), $request);
    }

    public function storeNeighborhood(Request $request): JsonResponse
    {
        return $this->storeModel(Neighborhood::class, $request->validate([
            'commune_id' => ['required', 'exists:communes,id'],
            'name' => ['required', 'string'],
            'name_fr' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateNeighborhood(Request $request, Neighborhood $neighborhood): JsonResponse
    {
        return $this->updateModel($neighborhood, $request->validate([
            'commune_id' => ['sometimes', 'exists:communes,id'],
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
            'is_active' => ['boolean'],
        ]));
    }

    public function destroyNeighborhood(Neighborhood $neighborhood): JsonResponse
    {
        return $this->destroyModel($neighborhood, ['offices']);
    }

    public function companies(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Company::query()
            ->with($this->companyRelations())
            ->withCount(['offices']);

        if ($request->boolean('business')) {
            $query->whereNotNull('system_module_id');
        }

        if (! $user->isGroupAdmin()) {
            $query->whereIn('id', $user->accessibleCompanyIds() ?: [0]);
        }

        return $this->list($query, $request);
    }

    public function storeCompany(Request $request): JsonResponse
    {
        $moduleId = $request->filled('system_module_id') ? (int) $request->integer('system_module_id') : null;
        $existingForModule = $moduleId
            ? Company::withTrashed()->where('system_module_id', $moduleId)->first()
            : null;

        $data = $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'system_module_id' => [
                'nullable',
                'exists:system_modules,id',
                Rule::unique('companies', 'system_module_id')
                    ->whereNull('deleted_at')
                    ->ignore($existingForModule?->id),
            ],
            'name' => ['required', 'string'],
            'legal_name' => ['nullable', 'string'],
            'code' => [
                'required',
                'string',
                Rule::unique('companies', 'code')
                    ->whereNull('deleted_at')
                    ->ignore($existingForModule?->id),
            ],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'tax_id' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        // Company currency always follows the country — never a separate reporting currency.
        $countryCode = Country::query()->whereKey($data['country_id'])->value('currency_code');
        $data['currency_code'] = strtoupper((string) ($countryCode ?: Currency::defaultCode()));

        // One company per module: update/restore when it already exists (soft-deleted or active).
        $company = $existingForModule;
        if (! $company) {
            $company = Company::onlyTrashed()->where('code', $data['code'])->first();
        }

        if ($company) {
            if ($company->trashed()) {
                $company->restore();
            }
            $company->fill($data)->save();
        } else {
            $company = Company::query()->create($data);
        }

        if (! empty($data['system_module_id'])) {
            $company->modules()->syncWithoutDetaching([
                (int) $data['system_module_id'] => ['is_enabled' => true],
            ]);
        }

        $this->ensureKamsRevenueRules($company);

        return $this->created(
            $company->fresh([...$this->companyRelations()])->loadCount('offices'),
            __('Created')
        );
    }

    public function showCompany(Company $company): JsonResponse
    {
        return $this->ok($company->load([...$this->companyRelations(), 'activities', 'modules']));
    }

    public function updateCompany(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['sometimes', 'exists:countries,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'system_module_id' => [
                'nullable',
                'exists:system_modules,id',
                Rule::unique('companies', 'system_module_id')->whereNull('deleted_at')->ignore($company->id),
            ],
            'name' => ['sometimes', 'string'],
            'legal_name' => ['nullable', 'string'],
            'code' => [
                'sometimes',
                'string',
                Rule::unique('companies', 'code')->whereNull('deleted_at')->ignore($company->id),
            ],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'tax_id' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $countryId = (int) ($data['country_id'] ?? $company->country_id);
        $countryCode = Country::query()->whereKey($countryId)->value('currency_code');
        if ($countryCode) {
            $data['currency_code'] = strtoupper((string) $countryCode);
        }

        $company->fill($data)->save();
        if (! empty($data['system_module_id'])) {
            $company->modules()->syncWithoutDetaching([
                (int) $data['system_module_id'] => ['is_enabled' => true],
            ]);
        }
        $this->ensureKamsRevenueRules($company);

        return $this->ok($company->fresh([...$this->companyRelations()])->loadCount('offices'));
    }

    public function destroyCompany(Company $company): JsonResponse
    {
        return $this->destroyModel($company);
    }

    public function offices(Request $request): JsonResponse
    {
        return $this->list(Office::query()->with($this->officeRelations())
            ->when($request->company_id, fn ($q) => $q->where('company_id', $request->company_id)), $request);
    }

    public function officeLookups(Request $request): JsonResponse
    {
        $companyId = $request->integer('company_id') ?: null;
        if ($companyId) {
            abort_unless($request->user()->canAccessCompany($companyId), 403);
        }

        $offices = Office::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->when(! $request->user()->isGroupAdmin(), function ($q) use ($request) {
                $q->whereIn('company_id', $request->user()->accessibleCompanyIds() ?: [0]);
                if ($request->user()->isOfficeScoped()) {
                    $q->whereIn('id', $request->user()->accessibleOfficeIds() ?: [0]);
                }
            })
            ->orderBy('name')
            ->get(['id', 'company_id', 'parent_office_id', 'name', 'code']);

        $users = User::query()
            ->where('is_active', true)
            ->when($companyId, function ($q) use ($companyId) {
                $q->where(function ($inner) use ($companyId) {
                    $inner->where('primary_company_id', $companyId)
                        ->orWhereHas('roles', fn ($roles) => $roles->where('role_user.company_id', $companyId));
                });
            })
            ->orderBy('name')
            ->limit(400)
            ->get(['id', 'name', 'email', 'primary_company_id']);

        $members = KamsMember::query()
            ->with('role:id,name,slug')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('status', ['active', 'verified'])
            ->whereNotNull('user_id')
            ->whereHas('role', fn ($q) => $q->whereIn('slug', ['manager', 'divisionnaire', 'vice_president', 'partner']))
            ->orderBy('full_name')
            ->get(['id', 'company_id', 'user_id', 'full_name', 'member_code', 'role_id', 'office_id']);

        return $this->ok([
            'offices' => $offices,
            'users' => $users,
            'members' => $members,
            'office_types' => ['headquarters', 'office', 'agency', 'branch'],
        ]);
    }

    public function storeOffice(Request $request, OfficeHierarchy $hierarchy): JsonResponse
    {
        $data = $this->validatedOffice($request);
        if ($request->user()->isOfficeScoped() && empty($data['parent_office_id'])) {
            $data['parent_office_id'] = $request->user()->primary_office_id
                ?: ($request->user()->accessibleOfficeIds()[0] ?? null);
        }
        $data['recorded_by_user_id'] = $request->user()->id;
        $office = Office::query()->create($data);
        $hierarchy->recordHistory($office, [], $office->only(['parent_office_id', 'creator_user_id', 'manager_user_id']), $request->user()->id);
        $hierarchy->assignKamsManager($office);

        return $this->created($office->fresh($this->officeRelations()));
    }

    public function updateOffice(Request $request, Office $office, OfficeHierarchy $hierarchy): JsonResponse
    {
        $data = $this->validatedOffice($request, $office);
        unset($data['recorded_by_user_id']);
        if (! empty($data['parent_office_id']) && $hierarchy->wouldCycle($office, (int) $data['parent_office_id'])) {
            return response()->json([
                'success' => false,
                'message' => __('The parent office cannot create a circular hierarchy.'),
            ], 422);
        }
        $before = $office->only(['parent_office_id', 'creator_user_id', 'manager_user_id']);
        $office->fill($data)->save();
        $hierarchy->recordHistory($office->fresh(), $before, $office->only(['parent_office_id', 'creator_user_id', 'manager_user_id']), $request->user()->id);
        $hierarchy->assignKamsManager($office);

        return $this->ok($office->fresh($this->officeRelations()));
    }

    public function destroyOffice(Office $office): JsonResponse
    {
        return $this->destroyModel($office);
    }

    public function activities(Request $request): JsonResponse
    {
        return $this->list(Activity::query()->with('company')->when($request->company_id, fn ($q) => $q->where('company_id', $request->company_id)), $request);
    }

    public function storeActivity(Request $request): JsonResponse
    {
        return $this->storeModel(Activity::class, $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateActivity(Request $request, Activity $activity): JsonResponse
    {
        return $this->updateModel($activity, $request->validate([
            'company_id' => ['sometimes', 'exists:companies,id'],
            'name' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function destroyActivity(Activity $activity): JsonResponse
    {
        return $this->destroyModel($activity);
    }

    private function list($query, Request $request): JsonResponse
    {
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $this->paginated($query->latest()->paginate($request->integer('per_page', 50)));
    }

    private function storeModel(string $class, array $data): JsonResponse
    {
        $model = $class::query()->create($data);

        return $this->created($model);
    }

    private function updateModel(Model $model, array $data): JsonResponse
    {
        $old = $model->toArray();
        $model->fill($data)->save();

        return $this->ok($model->fresh());
    }

    /**
     * @param  array<int, string>  $relations
     */
    private function destroyModel(Model $model, array $relations = []): JsonResponse
    {
        foreach ($relations as $relation) {
            if (method_exists($model, $relation) && $model->{$relation}()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This record is still in use and cannot be deleted.',
                ], 409);
            }
        }

        try {
            $model->delete();
        } catch (QueryException) {
            return response()->json([
                'success' => false,
                'message' => 'This record is still in use and cannot be deleted.',
            ], 409);
        }

        return $this->ok(null, __('Deleted.'));
    }

    /**
     * @return array<int, string>
     */
    private function ensureKamsRevenueRules(Company $company): void
    {
        $isKams = $company->module?->slug === 'kams'
            || ($company->system_module_id
                && SystemModule::query()
                    ->whereKey($company->system_module_id)
                    ->where('slug', 'kams')
                    ->exists());

        if ($isKams) {
            app(RevenueRuleService::class)->seedDefaults((int) $company->id);
        }
    }

    private function companyRelations(): array
    {
        return [
            'country', 'city', 'module',
            'offices.country', 'offices.province', 'offices.city', 'offices.commune', 'offices.neighborhood',
            'offices.parent', 'offices.creator', 'offices.manager', 'offices.recordedBy',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function officeRelations(): array
    {
        return ['company.module', 'country', 'province', 'city', 'commune', 'neighborhood', 'parent', 'creator', 'manager', 'recordedBy', 'children'];
    }

    private function validatedOffice(Request $request, ?Office $office = null): array
    {
        $companyId = $office?->company_id ?? $request->input('company_id');

        $data = $request->validate([
            'company_id' => [$office ? 'sometimes' : 'required', 'exists:companies,id'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'commune_id' => ['nullable', 'exists:communes,id'],
            'neighborhood_id' => ['nullable', 'exists:neighborhoods,id'],
            'name' => [$office ? 'sometimes' : 'required', 'string'],
            'code' => [$office ? 'sometimes' : 'required', 'string'],
            'type' => [$office ? 'sometimes' : 'required', 'in:headquarters,office,agency,branch'],
            'parent_office_id' => ['nullable', 'exists:offices,id'],
            'creator_user_id' => ['nullable', 'exists:users,id'],
            'manager_user_id' => ['nullable', 'exists:users,id'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        if (! empty($data['parent_office_id']) && $companyId) {
            $parentCompany = Office::query()->whereKey($data['parent_office_id'])->value('company_id');
            abort_unless((int) $parentCompany === (int) $companyId, 422, __('The parent office must belong to the same company.'));
        }

        return $data;
    }
}
