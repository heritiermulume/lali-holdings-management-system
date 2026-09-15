<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Core\Support\CurrencySync;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\City;
use App\Models\Commune;
use App\Models\Company;
use App\Models\Country;
use App\Models\Department;
use App\Models\Neighborhood;
use App\Models\Office;
use App\Models\Province;
use App\Support\CountryTaxCatalog;
use App\Support\CurrencyCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    use RespondsWithJson;

    public function tree(): JsonResponse
    {
        return $this->ok(
            Country::query()
                ->with(['provinces.cities.communes.neighborhoods', 'cities.communes.neighborhoods', 'companies.offices.departments'])
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
        $defaults = CountryTaxCatalog::for($data['iso_code']);
        $data['tax_name'] = $data['tax_name'] ?? $defaults['tax_name'];
        $data['tax_rate'] = $data['tax_rate'] ?? $defaults['tax_rate'];
        $response = $this->storeModel(Country::class, $data);
        CurrencySync::ensureFromCountries();

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
            'tax_name' => ['nullable', 'string', 'max:64'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['boolean'],
        ]);
        if (! empty($data['currency_code']) && empty($data['currency_symbol'])) {
            $data['currency_symbol'] = CurrencyCatalog::find($data['currency_code'])['symbol'];
        }
        $response = $this->updateModel($country, $data);
        CurrencySync::ensureFromCountries();

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
        return $this->storeModel(City::class, $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'name' => ['required', 'string'],
            'name_fr' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateCity(Request $request, City $city): JsonResponse
    {
        return $this->updateModel($city, $request->validate([
            'country_id' => ['sometimes', 'exists:countries,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
            'is_active' => ['boolean'],
        ]));
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
            'is_active' => ['boolean'],
        ]));
    }

    public function updateCommune(Request $request, Commune $commune): JsonResponse
    {
        return $this->updateModel($commune, $request->validate([
            'city_id' => ['sometimes', 'exists:cities,id'],
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
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
            'is_active' => ['boolean'],
        ]));
    }

    public function updateNeighborhood(Request $request, Neighborhood $neighborhood): JsonResponse
    {
        return $this->updateModel($neighborhood, $request->validate([
            'commune_id' => ['sometimes', 'exists:communes,id'],
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
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
            ->withCount(['offices', 'departments']);

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
        return $this->storeModel(Company::class, $request->validate([
            'country_id' => ['required', 'exists:countries,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'system_module_id' => ['nullable', 'exists:system_modules,id', 'unique:companies,system_module_id'],
            'name' => ['required', 'string'],
            'legal_name' => ['nullable', 'string'],
            'code' => ['required', 'string', 'unique:companies,code'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'tax_id' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function showCompany(Company $company): JsonResponse
    {
        return $this->ok($company->load([...$this->companyRelations(), 'departments', 'activities', 'modules']));
    }

    public function updateCompany(Request $request, Company $company): JsonResponse
    {
        return $this->updateModel($company, $request->validate([
            'country_id' => ['sometimes', 'exists:countries,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'system_module_id' => ['nullable', 'exists:system_modules,id', 'unique:companies,system_module_id,'.$company->id],
            'name' => ['sometimes', 'string'],
            'legal_name' => ['nullable', 'string'],
            'code' => ['sometimes', 'string', 'unique:companies,code,'.$company->id],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'tax_id' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
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

    public function storeOffice(Request $request): JsonResponse
    {
        $office = Office::query()->create($this->validatedOffice($request));
        AuditLogger::record('create', $office, [], $office->toArray());

        return $this->created($office->fresh($this->officeRelations()));
    }

    public function updateOffice(Request $request, Office $office): JsonResponse
    {
        return $this->updateModel($office, $this->validatedOffice($request, $office));
    }

    public function destroyOffice(Office $office): JsonResponse
    {
        return $this->destroyModel($office);
    }

    public function departments(Request $request): JsonResponse
    {
        return $this->list(Department::query()->with(['company', 'office'])->when($request->company_id, fn ($q) => $q->where('company_id', $request->company_id)), $request);
    }

    public function storeDepartment(Request $request): JsonResponse
    {
        return $this->storeModel(Department::class, $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateDepartment(Request $request, Department $department): JsonResponse
    {
        return $this->updateModel($department, $request->validate([
            'company_id' => ['sometimes', 'exists:companies,id'],
            'office_id' => ['nullable', 'exists:offices,id'],
            'name' => ['sometimes', 'string'],
            'code' => ['sometimes', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function destroyDepartment(Department $department): JsonResponse
    {
        return $this->destroyModel($department, ['users']);
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
        AuditLogger::record('create', $model, [], $data);

        return $this->created($model);
    }

    private function updateModel(Model $model, array $data): JsonResponse
    {
        $old = $model->toArray();
        $model->fill($data)->save();
        AuditLogger::record('update', $model, $old, $data);

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

        AuditLogger::record('delete', $model);

        return $this->ok(null, __('Deleted.'));
    }

    /**
     * @return array<int, string>
     */
    private function companyRelations(): array
    {
        return ['country', 'city', 'module', 'offices.country', 'offices.province', 'offices.city', 'offices.commune', 'offices.neighborhood'];
    }

    /**
     * @return array<int, string>
     */
    private function officeRelations(): array
    {
        return ['company.module', 'country', 'province', 'city', 'commune', 'neighborhood'];
    }

    private function validatedOffice(Request $request, ?Office $office = null): array
    {
        return $request->validate([
            'company_id' => [$office ? 'sometimes' : 'required', 'exists:companies,id'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'commune_id' => ['nullable', 'exists:communes,id'],
            'neighborhood_id' => ['nullable', 'exists:neighborhoods,id'],
            'name' => [$office ? 'sometimes' : 'required', 'string'],
            'code' => [$office ? 'sometimes' : 'required', 'string'],
            'type' => [$office ? 'sometimes' : 'required', 'in:headquarters,office,agency,branch'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);
    }
}
