@props([
    'regions',
    'provinceOptions' => [],
    'cities',
    'regionCode' => '',
    'provinceCode' => '',
])

{{-- Region, then province, then city or municipality, from the PSGC list. Each list narrows to the one above it. --}}
<div class="grid gap-4 sm:grid-cols-3">
    <flux:select wire:model.live="location.region_code" :label="__('Region')" required>
        <flux:select.option value="">{{ __('Choose a region') }}</flux:select.option>
        @foreach ($regions as $region)
            <flux:select.option value="{{ $region->code }}">{{ $region->name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select wire:model.live="location.province_code" :label="__('Province')" :disabled="$regionCode === ''" required>
        <flux:select.option value="">{{ $regionCode === '' ? __('Choose a region first') : __('Choose a province') }}</flux:select.option>
        @foreach ($provinceOptions as $code => $name)
            <flux:select.option value="{{ $code }}">{{ $name }}</flux:select.option>
        @endforeach
    </flux:select>

    <flux:select wire:model="location.city_code" :label="__('City or municipality')" :disabled="$provinceCode === ''" required>
        <flux:select.option value="">{{ $provinceCode === '' ? __('Choose a province first') : __('Choose a city or municipality') }}</flux:select.option>
        @foreach ($cities as $city)
            <flux:select.option value="{{ $city->code }}">{{ $city->name }}</flux:select.option>
        @endforeach
    </flux:select>
</div>
