@props([
    'occupancy',
    'bedrooms' => 0,
    'isStudio' => false,
    'hasSharedBathroom' => false,
    'amenityGroups' => collect(),
    'selectedAmenities' => [],
    'amenityLimits' => [],
    'amenityLimitReasons' => [],
    'amenityQuantities' => [],
    'bedSpaces' => 0,
    'bedSummary' => null,
    'maxCapacity' => null,
    'maxBedrooms' => null,
    'maxBathrooms' => null,
    'lockedUnit' => null,
    'examples' => false,
])

@php
    $limits = config('occuplace.units');
@endphp

<flux:input
    wire:model="form.unit_number"
    :label="__('Unit name')"
    :description="$examples ? __('A number or a name, like 101 or Sampaguita 2A.') : null"
    :placeholder="$examples ? '101' : null"
    maxlength="{{ $limits['name_max_length'] }}"
    required
    autofocus
/>

@if ($lockedUnit)
    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
        <div class="flex items-center gap-2">
            <flux:icon.lock-closed variant="micro" class="text-zinc-500 dark:text-zinc-400" />
            <flux:heading size="sm">{{ __('Fixed details') }}</flux:heading>
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Floor') }}</dt>
                <dd class="font-medium">{{ $lockedUnit->floor_level ?: __('Not set') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Floor area') }}</dt>
                <dd class="font-medium">{{ \App\Models\Unit::formatFloorArea((float) $lockedUnit->floor_area_sqm) }} m²</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Bedrooms') }}</dt>
                <dd class="font-medium">{{ $lockedUnit->bedrooms === 0 ? __('Studio or bedspace') : $lockedUnit->bedrooms }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Bathrooms') }}</dt>
                <dd class="font-medium">{{ $lockedUnit->bathrooms === 0 ? __('Shared') : $lockedUnit->bathrooms }}</dd>
            </div>
        </dl>

        <flux:text class="mt-3 text-xs">{{ __('These were set when the unit was added and cannot be changed.') }}</flux:text>
    </div>
@else
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select wire:model="form.floor_level" :label="__('Floor level')" :placeholder="__('Choose a floor')" required>
            @foreach (\App\Models\Unit::floorLevelOptions() as $level)
                <flux:select.option value="{{ $level }}">{{ $level }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:input
            wire:model.live.blur="form.floor_area_sqm"
            type="number"
            step="0.1"
            min="{{ $limits['floor_area']['min'] }}"
            max="{{ $limits['floor_area']['max'] }}"
            :label="__('Floor area (m²)')"
            :placeholder="$examples ? '24' : null"
            required
        />
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-3">
            <flux:checkbox
                wire:model.live="form.isStudio"
                :label="__('Studio or bedspace')"
                :description="__('No separate bedroom: one open room, or beds rented in a shared room.')"
            />

            @unless ($isStudio)
                <flux:input
                    wire:model.live.blur="form.bedrooms"
                    type="number"
                    min="1"
                    max="{{ max(1, $maxBedrooms ?? $limits['bedrooms']['max']) }}"
                    :label="__('Bedrooms')"
                    required
                />
            @endunless
        </div>

        <div class="space-y-3">
            <flux:checkbox
                wire:model.live="form.hasSharedBathroom"
                :label="__('Shared bathroom')"
                :description="__('Tenants use a bathroom outside the unit, like a common CR on the floor.')"
            />

            @unless ($hasSharedBathroom)
                <flux:input
                    wire:model.live.blur="form.bathrooms"
                    type="number"
                    min="1"
                    max="{{ max(1, $maxBathrooms ?? $limits['bathrooms']['max']) }}"
                    :label="__('Bathrooms')"
                    required
                />
            @endunless
        </div>
    </div>

    @if ($maxBedrooms !== null && ! $isStudio)
        <flux:text class="text-zinc-500 dark:text-zinc-400">
            {{ $maxBedrooms === 0
                ? __('This floor area is too small for a separate bedroom. Check "Studio or bedspace".')
                : trans_choice('This floor area fits up to :count bedroom, keeping :area m² for a kitchen and living area.|This floor area fits up to :count bedrooms, keeping :area m² for a kitchen and living area.', $maxBedrooms, ['area' => $limits['common_area']]) }}
        </flux:text>
    @endif

    <div class="flex items-start gap-2">
        <flux:icon.lock-closed variant="micro" class="mt-0.5 shrink-0 text-zinc-500 dark:text-zinc-400" />
        <flux:text class="text-xs">{{ __('The floor, floor area, bedrooms and bathrooms cannot be changed after you save.') }}</flux:text>
    </div>
@endif

<flux:input
    wire:model="form.price"
    type="number"
    step="0.01"
    min="{{ $limits['rent']['min'] }}"
    max="{{ $limits['rent']['max'] }}"
    :label="__('Monthly rent (₱)')"
    :placeholder="$examples ? '5000' : null"
    required
/>

@if ($amenityGroups->isNotEmpty())
    <flux:fieldset>
        <flux:legend>{{ __('Amenities') }}</flux:legend>
        <flux:description>{{ __('Check what comes with this unit and how many of each. The beds decide how many tenants the unit fits. Leave beds unchecked if tenants bring their own.') }}</flux:description>
        <flux:description>{{ __('How many you can add depends on the unit: beds on how many people the floor area fits, and items for each tenant, like chairs and cabinets, on how many people the checked beds sleep. Check more beds and those limits go up.') }}</flux:description>

        <flux:checkbox.group wire:model.live="form.amenityIds" class="mt-4 space-y-5">
            @foreach ($amenityGroups as $category => $amenities)
                <div class="space-y-2">
                    <flux:heading size="sm">{{ $category }}</flux:heading>

                    <div class="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                        @foreach ($amenities as $amenity)
                            @php
                                $isTicked = in_array((string) $amenity->id, $selectedAmenities, true);
                                $amenityLimit = $amenityLimits[$amenity->id] ?? $limits['amenity_quantity']['max'];
                                $asksQuantity = $isTicked && ! $amenity->isSingle()
                                    && ($amenityLimit !== 1 || (string) ($amenityQuantities[$amenity->id] ?? '1') !== '1');
                            @endphp

                            <div wire:key="amenity-{{ $amenity->id }}">
                                <div class="flex min-h-9 items-center justify-between gap-3">
                                    <flux:checkbox value="{{ $amenity->id }}" :label="$amenity->name" />

                                    @if ($asksQuantity)
                                        <div class="flex items-center gap-2">
                                            <flux:text size="sm" class="whitespace-nowrap">
                                                {{ $amenityLimit > 0 ? __('Up to :max', ['max' => $amenityLimit]) : __('No room left') }}
                                            </flux:text>

                                            <flux:input
                                                wire:model.blur="form.amenityQuantities.{{ $amenity->id }}"
                                                type="number"
                                                min="1"
                                                max="{{ max(1, $amenityLimit) }}"
                                                size="sm"
                                                class="max-w-20"
                                                :aria-label="__('How many :amenity', ['amenity' => $amenity->name])"
                                            />
                                        </div>
                                    @endif
                                </div>

                                @if ($asksQuantity && isset($amenityLimitReasons[$amenity->id]))
                                    <p class="mt-0.5 ps-7 text-sm leading-snug text-zinc-700">{{ $amenityLimitReasons[$amenity->id] }}</p>
                                @endif

                                @if ($isTicked)
                                    <flux:error name="form.amenityQuantities.{{ $amenity->id }}" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </flux:checkbox.group>

        @if ($bedSummary)
            <flux:callout icon="information-circle" class="mt-4">
                <flux:callout.text>
                    {{ trans_choice('Counting only the beds you checked: :beds sleep :count person. Check every bed in the unit, or none if tenants bring their own.|Counting only the beds you checked: :beds sleep :count people. Check every bed in the unit, or none if tenants bring their own.', $bedSpaces, ['beds' => $bedSummary]) }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:error name="form.amenityIds" />
        <flux:error name="form.amenityIds.*" />
    </flux:fieldset>
@endif

<flux:radio.group wire:model.live="form.occupancy" :label="__('Occupancy')" variant="segmented">
    <flux:radio value="single">{{ __('Single tenant') }}</flux:radio>
    <flux:radio value="multiple">{{ __('Multiple tenants') }}</flux:radio>
</flux:radio.group>

@if ($maxCapacity !== null)
    @php
        $bedroomCount = $lockedUnit ? $lockedUnit->bedrooms : (int) $bedrooms;
    @endphp

    <flux:text class="text-zinc-500 dark:text-zinc-400">
        @if ($occupancy !== 'multiple')
            {{ $maxCapacity > 1
                ? __('Choose multiple tenants to let up to :max people share this unit.', ['max' => $maxCapacity])
                : __('This unit fits 1 tenant.') }}
        @elseif ($bedSpaces > $maxCapacity)
            {{ __('The beds sleep :beds, but the floor area fits up to :max tenants.', ['beds' => $bedSpaces, 'max' => $maxCapacity]) }}
        @elseif ($bedSpaces > 0)
            {{ trans_choice('The beds sleep :count tenant.|The beds sleep up to :count tenants.', $maxCapacity) }}
        @elseif ($bedroomCount > 0)
            {{ trans_choice(':count bedroom fits up to :max tenants.|:count bedrooms fit up to :max tenants.', $bedroomCount, ['max' => $maxCapacity]) }}
        @else
            {{ $maxCapacity === 1
                ? __('This floor area fits 1 tenant.')
                : __('This floor area fits up to :max tenants.', ['max' => $maxCapacity]) }}
        @endif
    </flux:text>
@endif

@if ($occupancy === 'multiple')
    <flux:input
        wire:model="form.tenant_limit"
        type="number"
        min="2"
        max="{{ $maxCapacity ?? $limits['max_capacity'] }}"
        :label="__('Maximum tenants')"
        required
    />

    <flux:text class="text-zinc-500 dark:text-zinc-400">
        {{ __('Rent is split equally among all current tenants.') }}
    </flux:text>
@endif
