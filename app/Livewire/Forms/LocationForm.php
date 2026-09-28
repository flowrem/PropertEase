<?php

namespace App\Livewire\Forms;

use App\Models\City;
use App\Models\Property;
use App\Models\Province;
use App\Models\Region;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Form;

/**
 * A property's region, province and city or municipality, picked from the
 * PSGC list with three linked dropdowns. Metro Manila and the few places
 * under no province are picked with the NO_PROVINCE choice.
 */
class LocationForm extends Form
{
    /**
     * The province choice for cities that sit directly under their region.
     */
    public const NO_PROVINCE = 'none';

    public string $region_code = '';

    public string $province_code = '';

    public string $city_code = '';

    /**
     * Start from a property's saved location. One typed before the
     * dropdowns starts empty.
     */
    public function fillFromProperty(Property $property): void
    {
        $this->region_code = (string) $property->region_code;
        $this->province_code = $property->city_code === null ? '' : ($property->province_code ?? self::NO_PROVINCE);
        $this->city_code = (string) $property->city_code;
    }

    /**
     * Clear the choices below the one that just changed, so a city from
     * another province can't stay selected.
     */
    public function cascade(string $changedField): void
    {
        if ($changedField === 'region_code') {
            $this->province_code = '';
            $this->city_code = '';
        }

        if ($changedField === 'province_code') {
            $this->city_code = '';
        }
    }

    /**
     * @return Collection<int, Region>
     */
    public function regions(): Collection
    {
        return Region::query()->orderBy('code')->get();
    }

    /**
     * The provinces of the chosen region, as code => name, plus the choice
     * for its cities under no province: "Metro Manila" for the NCR.
     *
     * @return array<string, string>
     */
    public function provinceOptions(): array
    {
        if ($this->region_code === '') {
            return [];
        }

        $options = Province::query()->where('region_code', $this->region_code)->orderBy('name')->pluck('name', 'code')->all();

        if (City::query()->where('region_code', $this->region_code)->whereNull('province_code')->exists()) {
            $options[self::NO_PROVINCE] = $options === [] ? __('Metro Manila') : __('Not in a province');
        }

        return $options;
    }

    /**
     * @return Collection<int, City>
     */
    public function cities(): Collection
    {
        if ($this->region_code === '' || $this->province_code === '') {
            return new Collection;
        }

        return City::query()
            ->where('region_code', $this->region_code)
            ->when(
                $this->province_code === self::NO_PROVINCE,
                fn ($query) => $query->whereNull('province_code'),
                fn ($query) => $query->where('province_code', $this->province_code),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * The chosen location as property attributes: the three codes, plus the
     * city and province names for the typed columns every address shows.
     * The city must lie in the chosen province and region, so a crafted
     * request can't mix them.
     *
     * @return array{region_code: string, province_code: string|null, city_code: string, city: string, province: string}
     */
    public function validatedAttributes(): array
    {
        $this->validate([
            'region_code' => ['required', 'exists:regions,code'],
            'province_code' => ['required', 'string'],
            'city_code' => [
                'required',
                fn (string $attribute, mixed $value, Closure $fail) => $this->chosenCity() === null
                    ? $fail(__('Choose a city or municipality in the chosen province.'))
                    : null,
            ],
        ], attributes: [
            'region_code' => __('region'),
            'province_code' => __('province'),
            'city_code' => __('city or municipality'),
        ]);

        $city = $this->chosenCity();
        $region = Region::query()->findOrFail($this->region_code);

        if ($city->province !== null) {
            $provinceName = $city->province->name;
        } else {
            $provinceName = $region->isMetroManila() ? __('Metro Manila') : $region->name;
        }

        return [
            'region_code' => $city->region_code,
            'province_code' => $city->province_code,
            'city_code' => $city->code,
            'city' => $city->name,
            'province' => $provinceName,
        ];
    }

    /**
     * The chosen city, only if it matches the chosen region and province.
     */
    private function chosenCity(): ?City
    {
        return City::query()
            ->with('province')
            ->whereKey($this->city_code)
            ->where('region_code', $this->region_code)
            ->when(
                $this->province_code === self::NO_PROVINCE,
                fn ($query) => $query->whereNull('province_code'),
                fn ($query) => $query->where('province_code', $this->province_code),
            )
            ->first();
    }
}
