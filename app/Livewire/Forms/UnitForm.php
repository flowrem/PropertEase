<?php

namespace App\Livewire\Forms;

use App\Enums\PropertyType;
use App\Models\Unit;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * The add and edit unit form shared by the Properties page and the setup wizard.
 *
 * A unit's physical details (floor level, bedrooms, bathrooms, floor area)
 * are set once and then locked; its name, rent and occupancy stay editable.
 */
class UnitForm extends Form
{
    public string $unit_number = '';

    public string $floor_level = '';

    /**
     * Bedrooms, bathrooms and the tenant limit are kept as strings, like the
     * form's other numeric fields, so an oversized or malformed number a
     * landlord types in fails validation instead of crashing the page when
     * Livewire tries to coerce it into a typed int property.
     */
    public string $bedrooms = '1';

    public string $bathrooms = '1';

    public string $floor_area_sqm = '';

    public string $occupancy = 'single';

    public ?int $tenant_limit = null;

    public string $price = '';

    public function fillFromUnit(Unit $unit): void
    {
        $this->unit_number = $unit->unit_number;
        $this->floor_level = (string) $unit->floor_level;
        $this->bedrooms = (string) $unit->bedrooms;
        $this->bathrooms = (string) $unit->bathrooms;
        $this->floor_area_sqm = (string) $unit->floor_area_sqm;
        $this->occupancy = $unit->allows_multiple_tenants ? 'multiple' : 'single';
        $this->tenant_limit = $unit->tenant_limit;
        $this->price = (string) $unit->price;
    }

    /**
     * The most tenants the unit on the form can hold: from its saved floor
     * area once locked, otherwise from the area typed in, or null while that
     * is missing or out of range.
     */
    public function maxCapacity(PropertyType $type, ?Unit $unit = null): ?int
    {
        if ($unit?->hasLockedDetails()) {
            return $unit->maxCapacity();
        }

        if (! $this->hasUsableFloorArea()) {
            return null;
        }

        return Unit::maxCapacityFor((float) $this->floor_area_sqm, $type);
    }

    /**
     * The most bedrooms the typed floor area allows given the typed
     * bathrooms, or null while the area is missing or out of range.
     */
    public function maxBedrooms(): ?int
    {
        if (! $this->hasUsableFloorArea()) {
            return null;
        }

        return Unit::maxBedroomsFor((float) $this->floor_area_sqm, (int) $this->bathrooms);
    }

    /**
     * The most bathrooms the typed floor area allows given the typed
     * bedrooms, or null while the area is missing or out of range.
     */
    public function maxBathrooms(): ?int
    {
        if (! $this->hasUsableFloorArea()) {
            return null;
        }

        return Unit::maxBathroomsFor((float) $this->floor_area_sqm, (int) $this->bedrooms);
    }

    /**
     * Whether the typed floor area is a number within the configured range,
     * so it can be used to derive capacity and room limits.
     */
    private function hasUsableFloorArea(): bool
    {
        $limits = config('occuplace.units.floor_area');

        return is_numeric($this->floor_area_sqm)
            && (float) $this->floor_area_sqm >= $limits['min']
            && (float) $this->floor_area_sqm <= $limits['max'];
    }

    /**
     * Snap bedrooms (or bathrooms) back down the moment it's typed past what
     * the current floor area allows, so a landlord can't leave an
     * unrealistic number, like 15 bedrooms in a 24 m² unit, sitting in the
     * field: the browser's "max" attribute only marks the input invalid, it
     * doesn't stop an out-of-range value from being typed and left there.
     *
     * This only clamps the field the landlord is actively editing. Typing a
     * smaller floor area does not retroactively shrink rooms entered
     * earlier; that mismatch is instead caught by validation, so the
     * message points at the floor area they just changed.
     */
    public function updatedBedrooms(): void
    {
        $limits = config('occuplace.units.bedrooms');

        $this->bedrooms = $this->clampToRange($this->bedrooms, $limits['min'], $this->maxBedrooms() ?? $limits['max']);
    }

    public function updatedBathrooms(): void
    {
        $limits = config('occuplace.units.bathrooms');

        $this->bathrooms = $this->clampToRange($this->bathrooms, $limits['min'], $this->maxBathrooms() ?? $limits['max']);
    }

    private function clampToRange(string $value, int $min, int $max): string
    {
        if (! is_numeric($value)) {
            return (string) $min;
        }

        return (string) max($min, min($max, (int) $value));
    }

    /**
     * Validate the form for a new unit (no $unit) or an existing one, and
     * return the unit attributes to save. Physical details are only
     * validated and returned while the unit is not locked yet.
     *
     * @return array<string, mixed>
     */
    public function validatedAttributes(PropertyType $type, int $propertyId, ?Unit $unit = null): array
    {
        $validated = $this->validate($this->rulesFor($type, $propertyId, $unit), attributes: [
            'unit_number' => __('unit name'),
            'floor_level' => __('floor level'),
            'floor_area_sqm' => __('floor area'),
            'tenant_limit' => __('maximum tenants'),
            'price' => __('monthly rent'),
        ]);

        $allowsMultipleTenants = $validated['occupancy'] === 'multiple';

        $attributes = [
            'unit_number' => $validated['unit_number'],
            'allows_multiple_tenants' => $allowsMultipleTenants,
            'tenant_limit' => $allowsMultipleTenants ? $validated['tenant_limit'] : null,
            'price' => $validated['price'],
        ];

        if (array_key_exists('floor_area_sqm', $validated)) {
            $attributes += [
                'floor_level' => $validated['floor_level'],
                'bedrooms' => $validated['bedrooms'],
                'bathrooms' => $validated['bathrooms'],
                'floor_area_sqm' => $validated['floor_area_sqm'],
            ];
        }

        return $attributes;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rulesFor(PropertyType $type, int $propertyId, ?Unit $unit): array
    {
        $limits = config('occuplace.units');
        $maxCapacity = $this->maxCapacity($type, $unit);
        $takenSlots = $unit?->takenSlotCount() ?? 0;

        $rules = [
            'unit_number' => [
                'required', 'string', 'max:'.$limits['name_max_length'],
                Rule::unique('units', 'unit_number')->where('property_id', $propertyId)->ignore($unit),
            ],
            'occupancy' => [
                'required', Rule::in(['single', 'multiple']),
                function (string $attribute, mixed $value, Closure $fail) use ($maxCapacity, $takenSlots): void {
                    if ($value === 'multiple' && $maxCapacity !== null && $maxCapacity < 2 && $takenSlots < 2) {
                        $fail(__('This unit is too small for more than one tenant.'));
                    }

                    if ($value === 'single' && $takenSlots > 1) {
                        $fail(__(':count tenants or reservations already hold this unit, so it has to stay shared.', ['count' => $takenSlots]));
                    }
                },
            ],
            'tenant_limit' => [
                $this->occupancy === 'multiple' ? 'required' : 'nullable',
                'integer', 'min:2', 'max:'.$limits['max_capacity'],
                function (string $attribute, mixed $value, Closure $fail) use ($maxCapacity, $takenSlots): void {
                    if ($this->occupancy !== 'multiple') {
                        return;
                    }

                    if ($maxCapacity !== null && $value > max($maxCapacity, $takenSlots)) {
                        $fail(__('This unit fits at most :max tenants.', ['max' => $maxCapacity]));
                    }

                    if ($value < $takenSlots) {
                        $fail(__(':count tenants or reservations already hold this unit.', ['count' => $takenSlots]));
                    }
                },
            ],
            'price' => ['required', 'numeric', 'min:'.$limits['rent']['min'], 'max:'.$limits['rent']['max']],
        ];

        if ($unit?->hasLockedDetails()) {
            return $rules;
        }

        $maxBedrooms = $this->maxBedrooms();
        $maxBathrooms = $this->maxBathrooms();

        return [
            ...$rules,
            'floor_level' => ['required', Rule::in(Unit::floorLevelOptions())],
            'bedrooms' => [
                'required', 'integer', 'min:'.$limits['bedrooms']['min'], 'max:'.$limits['bedrooms']['max'],
                function (string $attribute, mixed $value, Closure $fail) use ($maxBedrooms): void {
                    if ($maxBedrooms !== null && $value > $maxBedrooms) {
                        $fail(__('This floor area fits at most :max bedrooms.', ['max' => $maxBedrooms]));
                    }
                },
            ],
            'bathrooms' => [
                'required', 'integer', 'min:'.$limits['bathrooms']['min'], 'max:'.$limits['bathrooms']['max'],
                function (string $attribute, mixed $value, Closure $fail) use ($maxBathrooms): void {
                    if ($maxBathrooms !== null && $value > $maxBathrooms) {
                        $fail(__('This floor area fits at most :max bathrooms.', ['max' => $maxBathrooms]));
                    }
                },
            ],
            'floor_area_sqm' => [
                'required', 'numeric', 'decimal:0,1',
                'min:'.$limits['floor_area']['min'], 'max:'.$limits['floor_area']['max'],
                function (string $attribute, mixed $value, Closure $fail): void {
                    $minimum = Unit::minimumFloorAreaFor((int) $this->bedrooms, (int) $this->bathrooms);

                    if ((float) $value < $minimum) {
                        $fail(__('These rooms need at least :area m² of floor area.', ['area' => Unit::formatFloorArea($minimum)]));
                    }
                },
            ],
        ];
    }
}
