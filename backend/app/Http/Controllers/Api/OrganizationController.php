<?php

namespace App\Http\Controllers\Api;

use App\Core\Support\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\RespondsWithJson;
use App\Models\Activity;
use App\Models\City;
use App\Models\Company;
use App\Models\Country;
use App\Models\Department;
use App\Models\Office;
use App\Models\Province;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    use RespondsWithJson;

    public function tree(): JsonResponse
    {
        return $this->ok(
            Country::query()
                ->with(['provinces.cities', 'cities', 'companies.offices.departments'])
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
        return $this->storeModel(Country::class, $request->validate([
            'name' => ['required', 'string'],
            'name_fr' => ['required', 'string'],
            'iso_code' => ['required', 'string', 'max:8', 'unique:countries,iso_code'],
            'phone_code' => ['nullable', 'string', 'max:8'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'currency_symbol' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateCountry(Request $request, Country $country): JsonResponse
    {
        return $this->updateModel($country, $request->validate([
            'name' => ['sometimes', 'string'],
            'name_fr' => ['sometimes', 'string'],
            'iso_code' => ['sometimes', 'string', 'max:8', 'unique:countries,iso_code,'.$country->id],
            'phone_code' => ['nullable', 'string'],
            'currency_code' => ['nullable', 'string'],
            'currency_symbol' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function destroyCountry(Country $country): JsonResponse
    {
        return $this->destroyModel($country);
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
        return $this->destroyModel($province);
    }

    public function cities(Request $request): JsonResponse
    {
        return $this->list(City::query()->with(['country', 'province'])->when($request->country_id, fn ($q) => $q->where('country_id', $request->country_id)), $request);
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
        return $this->destroyModel($city);
    }

    public function companies(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Company::query()->with(['country', 'city'])->withCount(['offices', 'departments']);

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
        return $this->ok($company->load(['country', 'city', 'offices.city', 'departments', 'activities', 'modules']));
    }

    public function updateCompany(Request $request, Company $company): JsonResponse
    {
        return $this->updateModel($company, $request->validate([
            'country_id' => ['sometimes', 'exists:countries,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
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
        return $this->list(Office::query()->with(['company', 'city'])->when($request->company_id, fn ($q) => $q->where('company_id', $request->company_id)), $request);
    }

    public function storeOffice(Request $request): JsonResponse
    {
        return $this->storeModel(Office::class, $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'name' => ['required', 'string'],
            'code' => ['required', 'string'],
            'type' => ['required', 'in:headquarters,office,agency,branch'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
    }

    public function updateOffice(Request $request, Office $office): JsonResponse
    {
        return $this->updateModel($office, $request->validate([
            'company_id' => ['sometimes', 'exists:companies,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'name' => ['sometimes', 'string'],
            'code' => ['sometimes', 'string'],
            'type' => ['sometimes', 'in:headquarters,office,agency,branch'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]));
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
        return $this->destroyModel($department);
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

        return $this->paginated($query->latest()->paginate($request->integer('per_page', 20)));
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

    private function destroyModel(Model $model): JsonResponse
    {
        $model->delete();
        AuditLogger::record('delete', $model);

        return $this->ok(null, __('Deleted.'));
    }
}
