<?php

namespace App\Livewire\Forms;

use App\Enums\PropertyType;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    public string $tenant_limit = '';

    public string $price = '';

    /**
     * IDs of the amenities ticked on the form, as the checkboxes send them.
     *
     * @var array<int, string>
     */
    public array $amenityIds = [];

    /**
     * How many of each ticked amenity the unit has, keyed by amenity ID.
     *
     * @var array<int|string, string>
     */
    public array $amenityQuantities = [];

    public function fillFromUnit(Unit $unit): void
    {
        $quantities = $unit->amenities()->pluck('amenity_unit.quantity', 'amenities.id');

        $this->amenityIds = $quantities->keys()->map(fn (int $amenityId): string => (string) $amenityId)->all();
        $this->amenityQuantities = $quantities->map(fn (int $quantity): string => (string) $quantity)->all();

        $this->unit_number = $unit->unit_number;
        $this->floor_level = (string) $unit->floor_level;
        $this->bedrooms = (string) $unit->bedrooms;
        $this->bathrooms = (string) $unit->bathrooms;
        $this->floor_area_sqm = (string) $unit->floor_area_sqm;
        $this->occupancy = $unit->allows_multiple_tenants ? 'multiple' : 'single';
        $this->tenant_limit = $unit->tenant_limit !== null ? (string) $unit->tenant_limit : '';
        $this->price = (string) $unit->price;
    }

    /**
     * The most tenants the unit on the form can hold, from the beds ticked
     * on the form (beds stay editable) and the floor area and bedrooms:
     * the saved ones once locked, otherwise the ones typed in. Null while
     * the area is missing or out of range.
     */
    public function maxCapacity(PropertyType $type, ?Unit $unit = null): ?int
    {
        $dimensions = $this->dimensions($unit);

        if ($dimensions === null) {
            return null;
        }

        return Unit::maxCapacityFor($dimensions['floorArea'], $type, $dimensions['bedrooms'], $this->bedSpaces());
    }

    /**
     * The floor area and rooms of the unit on the form: the saved ones once
     * locked, otherwise the ones typed in. Null while the area is missing or
     * out of range, since nothing can be worked out from it yet.
     *
     * @return array{floorArea: float, bedrooms: int, bathrooms: int}|null
     */
    private function dimensions(?Unit $unit): ?array
    {
        if ($unit?->hasLockedDetails()) {
            return [
                'floorArea' => (float) $unit->floor_area_sqm,
                'bedrooms' => $unit->bedrooms,
                'bathrooms' => $unit->bathrooms,
            ];
        }

        if (! $this->hasUsableFloorArea()) {
            return null;
        }

        return [
            'floorArea' => (float) $this->floor_area_sqm,
            'bedrooms' => (int) $this->bedrooms,
            'bathrooms' => (int) $this->bathrooms,
        ];
    }

    /**
     * The most of each ticked amenity this unit can have, keyed by amenity
     * ID. Beds are limited by the floor space they may cover and by the
     * tenants the floor area fits, after the other beds ticked; everything
     * else by its own basis (per unit, room, bathroom or tenant). Until the
     * floor area is usable, only the absolute ceiling applies.
     *
     * @return array<int, int>
     */
    public function amenityQuantityLimits(PropertyType $type, ?Unit $unit = null): array
    {
        $ceiling = (int) config('occuplace.units.amenity_quantity.max');
        $ticked = $this->tickedAmenities();
        $dimensions = $this->dimensions($unit);

        if ($dimensions === null) {
            return $ticked->mapWithKeys(fn (Amenity $amenity): array => [
                $amenity->id => $amenity->isSingle() ? 1 : $ceiling,
            ])->all();
        }

        $beds = $this->tickedBeds();
        $bedFloorSpace = Unit::bedFloorSpaceFor($dimensions['floorArea'], $dimensions['bedrooms'], $dimensions['bathrooms']);
        $tenantsThatFit = Unit::tenantsThatFitIn($dimensions['floorArea'], $type);
        $maxCapacity = Unit::maxCapacityFor($dimensions['floorArea'], $type, $dimensions['bedrooms'], $this->bedSpaces());

        return $ticked->mapWithKeys(function (Amenity $amenity) use ($beds, $bedFloorSpace, $tenantsThatFit, $maxCapacity, $dimensions, $ceiling): array {
            if (! $amenity->isBed()) {
                $limit = $amenity->quantity_basis->maxQuantity($amenity->quantity_per, $dimensions['bedrooms'], $dimensions['bathrooms'], $maxCapacity);

                return [$amenity->id => min($ceiling, $limit)];
            }

            $otherBeds = $beds->reject(fn (array $bed): bool => $bed['amenity']->id === $amenity->id);
            $floorSpaceLeft = $bedFloorSpace - $otherBeds->sum(fn (array $bed): float => (float) $bed['amenity']->footprint_sqm * $bed['quantity']);
            $tenantsLeft = $tenantsThatFit - $otherBeds->sum(fn (array $bed): int => $bed['amenity']->sleeps * $bed['quantity']);

            $limit = (int) floor($tenantsLeft / $amenity->sleeps + 1e-9);

            if ((float) $amenity->footprint_sqm > 0) {
                $limit = min($limit, (int) floor($floorSpaceLeft / (float) $amenity->footprint_sqm + 1e-9));
            }

            return [$amenity->id => max(0, min($ceiling, $limit))];
        })->all();
    }

    /**
     * Snap an amenity's quantity back within what the unit can have the
     * moment it's typed, the same way bedrooms and bathrooms clamp
     * themselves. A bed with no room left at all keeps its quantity, so
     * validation can say why instead of the number silently changing.
     */
    public function clampAmenityQuantity(string $amenityId, PropertyType $type, ?Unit $unit = null): void
    {
        $limit = $this->amenityQuantityLimits($type, $unit)[(int) $amenityId] ?? null;
        $quantity = $this->amenityQuantities[$amenityId] ?? null;

        if ($limit === null || $limit < 1 || ! is_numeric($quantity)) {
            return;
        }

        $this->amenityQuantities[$amenityId] = (string) max(1, min($limit, (int) $quantity));
    }

    /**
     * The amenities ticked on the form.
     *
     * @return EloquentCollection<int, Amenity>
     */
    private function tickedAmenities(): EloquentCollection
    {
        if ($this->amenityIds === []) {
            return new EloquentCollection;
        }

        return Amenity::query()->whereIn('id', array_filter($this->amenityIds, 'is_numeric'))->get();
    }

    /**
     * How many people the beds ticked on the form sleep.
     */
    public function bedSpaces(): int
    {
        return (int) $this->tickedBeds()->sum(fn (array $bed): int => $bed['amenity']->sleeps * $bed['quantity']);
    }

    /**
     * The ticked beds in words, like "2 double decks and 1 single bed", or
     * null when no bed is ticked.
     */
    public function bedSummary(): ?string
    {
        $beds = $this->tickedBeds()->map(fn (array $bed): string => $bed['quantity'].' '
            .Str::plural(Str::lower($bed['amenity']->name), $bed['quantity']));

        return $beds->isEmpty() ? null : Arr::join($beds->all(), ', ', ' and ');
    }

    /**
     * Keep a shared unit's tenant limit in step with its maximum when the
     * beds change: a limit that was empty or sat at the old maximum follows
     * the new one up or down, and a limit the new maximum no longer allows
     * comes down to it. A limit the landlord set lower on purpose stays.
     */
    public function followMaxCapacity(?int $previousMaxCapacity, ?int $maxCapacity, int $takenSlots): void
    {
        if ($this->occupancy !== 'multiple' || $maxCapacity === null) {
            return;
        }

        $limit = is_numeric($this->tenant_limit) ? (int) $this->tenant_limit : null;

        if ($limit === null || $limit === $previousMaxCapacity || $limit > $maxCapacity) {
            $this->tenant_limit = (string) $maxCapacity;
            $this->clampTenantLimit($maxCapacity, $takenSlots);
        }
    }

    /**
     * The ticked amenities that sleep someone, with how many of each. A
     * ticked bed with no quantity yet counts as one, matching the default
     * updatedAmenityIds() gives it; a quantity that isn't a whole number
     * within bounds counts as none, since it fails validation anyway.
     *
     * @return Collection<int, array{amenity: Amenity, quantity: positive-int}>
     */
    private function tickedBeds(): Collection
    {
        if ($this->amenityIds === []) {
            return collect();
        }

        $maxQuantity = (int) config('occuplace.units.amenity_quantity.max');

        return Amenity::query()
            ->whereIn('id', array_filter($this->amenityIds, 'is_numeric'))
            ->where('sleeps', '>', 0)
            ->orderByDesc('sleeps')
            ->orderBy('name')
            ->get()
            ->map(function (Amenity $bed) use ($maxQuantity): array {
                $quantity = (string) ($this->amenityQuantities[$bed->id] ?? '1');

                return [
                    'amenity' => $bed,
                    'quantity' => ctype_digit($quantity) && (int) $quantity <= $maxQuantity ? (int) $quantity : 0,
                ];
            })
            ->filter(fn (array $bed): bool => $bed['quantity'] > 0)
            ->values();
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
     * Snap the tenant limit back down the moment it's typed past what this
     * unit can hold, the same way bedrooms and bathrooms clamp themselves.
     * The form has no property type or existing unit of its own to work out
     * the ceiling from, so the caller passes in the max capacity (from
     * maxCapacity()) and how many slots are already taken.
     */
    public function clampTenantLimit(?int $maxCapacity, int $takenSlots): void
    {
        if ($this->occupancy !== 'multiple' || ! is_numeric($this->tenant_limit)) {
            return;
        }

        $min = max(2, $takenSlots);
        $max = $maxCapacity !== null
            ? max($maxCapacity, $takenSlots)
            : (int) config('occuplace.units.max_capacity');

        $this->tenant_limit = (string) max($min, min($max, (int) $this->tenant_limit));
    }

    /**
     * Give a newly ticked amenity a quantity of 1, so the landlord only has
     * to type one when the unit has more than one of it.
     */
    public function updatedAmenityIds(): void
    {
        foreach ($this->amenityIds as $amenityId) {
            $this->amenityQuantities[$amenityId] ??= '1';
        }
    }

    /**
     * The amenities this form may put on the unit: the platform defaults
     * and the team's own, active ones, plus any inactive ones the unit
     * already has so editing it doesn't silently drop them.
     *
     * @return EloquentCollection<int, Amenity>
     */
    public function selectableAmenities(Team $team, ?Unit $unit = null): EloquentCollection
    {
        $attachedIds = $unit?->amenities()->pluck('amenities.id')->all() ?? [];

        return Amenity::availableTo($team)
            ->where(fn (Builder $selectable) => $selectable
                ->where('is_active', true)
                ->orWhereIn('id', $attachedIds))
            ->orderBy('name')
            ->get();
    }

    /**
     * The selectable amenities grouped under their category labels, in the
     * category order the enum lists them, for the form's checkboxes.
     *
     * @return Collection<string, EloquentCollection<int, Amenity>>
     */
    public function amenityGroups(Team $team, ?Unit $unit = null): Collection
    {
        return Amenity::groupByCategory($this->selectableAmenities($team, $unit));
    }

    /**
     * Save the validated amenities onto the unit, replacing what it had.
     * Call after validatedAttributes(), which checks every ID and quantity.
     */
    public function syncAmenities(Unit $unit): void
    {
        $unit->amenities()->sync(
            collect($this->amenityIds)
                ->unique()
                ->mapWithKeys(fn (string $amenityId): array => [
                    (int) $amenityId => ['quantity' => (int) $this->amenityQuantities[$amenityId]],
                ])
                ->all(),
        );
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
            'amenityIds.*' => __('amenity'),
            'amenityQuantities.*' => __('quantity'),
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
            'tenant_limit' => $this->occupancy !== 'multiple'
                ? ['nullable']
                : [
                    'required', 'integer', 'min:2', 'max:'.$limits['max_capacity'],
                    function (string $attribute, mixed $value, Closure $fail) use ($maxCapacity, $takenSlots): void {
                        if ($maxCapacity !== null && $value > max($maxCapacity, $takenSlots)) {
                            $fail(__('This unit fits at most :max tenants.', ['max' => $maxCapacity]));
                        }

                        if ($value < $takenSlots) {
                            $fail(__(':count tenants or reservations already hold this unit.', ['count' => $takenSlots]));
                        }
                    },
                ],
            'price' => ['required', 'numeric', 'min:'.$limits['rent']['min'], 'max:'.$limits['rent']['max']],
            ...$this->amenityRules($type, $propertyId, $unit, $maxCapacity, $takenSlots),
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

    /**
     * Only amenities this team may use can be ticked, each with a quantity
     * within bounds, so a crafted request can't attach another team's
     * custom amenity.
     *
     * Beds decide capacity, so removing beds is refused when fewer would no
     * longer sleep the tenants and reservations already holding the unit.
     * A unit that was already over its capacity before this edit (say its
     * floor area was entered after tenants moved in) can still be saved, as
     * long as the edit doesn't shrink it further.
     *
     * @return array<string, array<int, mixed>>
     */
    private function amenityRules(PropertyType $type, int $propertyId, ?Unit $unit, ?int $maxCapacity, int $takenSlots): array
    {
        $team = Property::query()->findOrFail($propertyId)->team;
        $selectableIds = $this->selectableAmenities($team, $unit)->pluck('id')->all();
        $maxQuantity = (int) config('occuplace.units.amenity_quantity.max');
        $savedMaxCapacity = $unit?->maxCapacity();

        $rules = [
            'amenityIds' => [
                'array',
                function (string $attribute, mixed $value, Closure $fail) use ($maxCapacity, $takenSlots, $savedMaxCapacity): void {
                    $shrinks = $maxCapacity !== null && $savedMaxCapacity !== null && $maxCapacity < $savedMaxCapacity;

                    if ($shrinks && $maxCapacity < $takenSlots) {
                        $fail(__(':count tenants or reservations already hold this unit, so its beds need to sleep at least :count.', ['count' => $takenSlots]));
                    }
                },
            ],
            'amenityIds.*' => ['integer', 'distinct', Rule::in($selectableIds)],
        ];

        $limits = $this->amenityQuantityLimits($type, $unit);
        $amenities = $this->tickedAmenities()->keyBy('id');

        foreach ($this->amenityIds as $amenityId) {
            $rules["amenityQuantities.{$amenityId}"] = [
                'required', 'integer', 'min:1', 'max:'.$maxQuantity,
                function (string $attribute, mixed $value, Closure $fail) use ($limits, $amenities, $amenityId): void {
                    $amenity = $amenities->get((int) $amenityId);
                    $limit = $limits[(int) $amenityId] ?? null;

                    if ($amenity === null || $limit === null || (int) $value <= $limit) {
                        return;
                    }

                    if (! $amenity->isBed()) {
                        $fail(__('This unit can have at most :max (:rule).', [
                            'max' => $limit,
                            'rule' => $amenity->quantity_basis->describe($amenity->quantity_per),
                        ]));
                    } elseif ($limit === 0) {
                        $fail(__('There is no floor space or tenant room left for this bed with the other beds ticked.'));
                    } else {
                        $fail(__('The floor area has room for at most :max with the other beds ticked.', ['max' => $limit]));
                    }
                },
            ];
        }

        return $rules;
    }
}
