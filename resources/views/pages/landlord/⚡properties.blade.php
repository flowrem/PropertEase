<?php

use App\Enums\ConcernStatus;
use App\Enums\ListingStatus;
use App\Enums\UnitStatus;
use App\Livewire\Forms\UnitForm;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Properties')] class extends Component
{
    public UnitForm $form;

    public bool $showConfirmUnitModal = false;

    /**
     * IDs of properties currently expanded in the UI.
     *
     * @var array<int, true>
     */
    public array $expanded = [];

    public ?int $addingUnitTo = null;

    public bool $showPropertyModal = false;

    public ?int $editingPropertyId = null;

    public string $property_name = '';

    public string $address_line = '';

    public string $city = '';

    public string $province = '';

    public string $postal_code = '';

    public string $map_url = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, Property>
     */
    #[Computed]
    public function properties(): Collection
    {
        return $this->team->properties()
            ->with(['units' => fn ($units) => $units
                ->with(['activeLeases.tenant', 'activeLeases.currentInvoice'])
                ->withBedSpaces()
                ->withCount(['heldReservations', 'concerns as open_concerns_count' => fn ($concerns) => $concerns
                    ->where('concerns.status', '!=', ConcernStatus::Resolved->value)]),
            ])
            ->latest()
            ->get();
    }

    /**
     * The property a unit is being added to.
     */
    #[Computed]
    public function formProperty(): ?Property
    {
        return $this->addingUnitTo ? $this->team->properties()->find($this->addingUnitTo) : null;
    }

    #[Computed]
    public function formMaxCapacityPreview(): ?int
    {
        return $this->formProperty
            ? $this->form->maxCapacity($this->formProperty->type)
            : null;
    }

    /**
     * @return Collection<string, EloquentCollection<int, Amenity>>
     */
    #[Computed]
    public function formAmenityGroups(): Collection
    {
        return $this->form->amenityGroups($this->team);
    }

    /**
     * The most of each ticked amenity the unit on the form can have.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function formAmenityLimits(): array
    {
        return $this->formProperty
            ? $this->form->amenityQuantityLimits($this->formProperty->type)
            : [];
    }

    #[Computed]
    public function formBedSpacesPreview(): int
    {
        return $this->form->bedSpaces();
    }

    #[Computed]
    public function formMaxBedroomsPreview(): ?int
    {
        return $this->form->maxBedrooms();
    }

    #[Computed]
    public function formMaxBathroomsPreview(): ?int
    {
        return $this->form->maxBathrooms();
    }

    /**
     * Snap the typed tenant limit back down as soon as it exceeds what this
     * unit can hold, the same way bedrooms and bathrooms clamp themselves.
     */
    public function updatedFormTenantLimit(): void
    {
        $this->form->clampTenantLimit($this->formMaxCapacityPreview, takenSlots: 0);
    }

    /**
     * The unit's maximum capacity just before its beds changed on the form,
     * so updated() can tell whether the tenant limit was sitting at it.
     */
    protected ?int $maxCapacityBeforeBedsChanged = null;

    public function updating(string $property): void
    {
        if (Str::startsWith($property, ['form.amenityIds', 'form.amenityQuantities'])) {
            $this->maxCapacityBeforeBedsChanged = $this->formMaxCapacityPreview;
            unset($this->formMaxCapacityPreview);
        }
    }

    /**
     * Let a shared unit's tenant limit follow its maximum when beds are
     * ticked, unticked or recounted.
     */
    public function updated(string $property): void
    {
        if (Str::startsWith($property, 'form.amenityQuantities.') && $this->formProperty) {
            $this->form->clampAmenityQuantity(Str::after($property, 'form.amenityQuantities.'), $this->formProperty->type);
        }

        if (Str::startsWith($property, ['form.amenityIds', 'form.amenityQuantities'])) {
            unset($this->formMaxCapacityPreview, $this->formBedSpacesPreview);

            $this->form->followMaxCapacity($this->maxCapacityBeforeBedsChanged, $this->formMaxCapacityPreview, takenSlots: 0);
        }
    }

    public function toggleProperty(int $propertyId): void
    {
        if (isset($this->expanded[$propertyId])) {
            unset($this->expanded[$propertyId]);
        } else {
            $this->expanded[$propertyId] = true;
        }
    }

    public function startAddingUnit(int $propertyId): void
    {
        $property = $this->team->properties()->findOrFail($propertyId);

        $this->addingUnitTo = $property->id;
        $this->expanded[$property->id] = true;
        $this->form->reset();
        $this->resetValidation();
        unset($this->formProperty);
    }

    public function cancelAddingUnit(): void
    {
        $this->addingUnitTo = null;
        $this->showConfirmUnitModal = false;
    }

    /**
     * Validate the new unit, then ask the landlord to confirm the details
     * that will be locked once it is saved.
     */
    public function reviewNewUnit(): void
    {
        $property = $this->team->properties()->findOrFail($this->addingUnitTo);

        $this->form->validatedAttributes($property->type, $property->id);

        $this->showConfirmUnitModal = true;
    }

    public function closeConfirmUnitModal(): void
    {
        $this->showConfirmUnitModal = false;
    }

    public function addUnit(): void
    {
        $property = $this->team->properties()->findOrFail($this->addingUnitTo);

        $attributes = $this->form->validatedAttributes($property->type, $property->id);

        DB::transaction(function () use ($property, $attributes): void {
            $unit = $property->units()->create([...$attributes, 'status' => UnitStatus::Vacant]);

            $this->form->syncAmenities($unit);
        });

        $this->showConfirmUnitModal = false;
        $this->addingUnitTo = null;
        unset($this->properties);

        Flux::toast(variant: 'success', text: __('Unit added.'));
    }

    public function startEditingProperty(int $propertyId): void
    {
        $property = $this->team->properties()->findOrFail($propertyId);

        $this->resetValidation();
        $this->editingPropertyId = $property->id;
        $this->property_name = $property->name;
        $this->address_line = $property->address_line;
        $this->city = $property->city;
        $this->province = $property->province;
        $this->postal_code = $property->postal_code;
        $this->map_url = (string) $property->map_url;
        $this->showPropertyModal = true;
    }

    public function closePropertyModal(): void
    {
        $this->showPropertyModal = false;
        $this->editingPropertyId = null;
    }

    /**
     * Save a property's name and address. The type is fixed once created.
     * Moving the address sends the property's live listings back for review,
     * so a listing never changes location after it was approved.
     */
    public function updateProperty(): void
    {
        $property = $this->team->properties()->findOrFail($this->editingPropertyId);

        $validated = $this->validate([
            'property_name' => ['required', 'string', 'max:255'],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'map_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $sentBackForReview = DB::transaction(function () use ($property, $validated) {
            $property->update([
                'name' => $validated['property_name'],
                'address_line' => $validated['address_line'],
                'city' => $validated['city'],
                'province' => $validated['province'],
                'postal_code' => $validated['postal_code'],
                'map_url' => $validated['map_url'] ?: null,
            ]);

            if (! $property->wasChanged(['address_line', 'city', 'province', 'postal_code', 'map_url'])) {
                return 0;
            }

            return UnitListing::query()
                ->whereIn('unit_id', $property->units()->select('id'))
                ->where('status', ListingStatus::Approved->value)
                ->update(['status' => ListingStatus::PendingReview->value, 'submitted_at' => now()]);
        });

        $this->closePropertyModal();
        unset($this->properties);

        Flux::toast(variant: 'success', text: $sentBackForReview > 0
            ? __('Property saved. Its live listings went back for review because the address changed.')
            : __('Property saved.'));
    }

}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Properties') }}</flux:heading>
            <flux:subheading>{{ __('Every property and unit under :team.', ['team' => $this->team->name]) }}</flux:subheading>
        </div>

        <flux:button :href="route('setup')" variant="primary" icon="plus" wire:navigate>
            {{ __('Add property') }}
        </flux:button>
    </div>

    @forelse ($this->properties as $property)
        @php($occupiedCount = $property->units->where('status', UnitStatus::Occupied)->count())
        <div wire:key="property-{{ $property->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div
                wire:click="toggleProperty({{ $property->id }})"
                class="flex w-full cursor-pointer items-center justify-between gap-4 p-4 text-left"
            >
                <div>
                    <flux:heading>{{ $property->name }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ $property->type->label() }} &middot; {{ $property->address_line }}, {{ $property->city }}, {{ $property->province }}
                    </flux:text>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <flux:badge color="zinc">
                        {{ __(':occupied/:total occupied', ['occupied' => $occupiedCount, 'total' => $property->units->count()]) }}
                    </flux:badge>
                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="pencil-square"
                        :aria-label="__('Edit property')"
                        wire:click.stop="startEditingProperty({{ $property->id }})"
                    />
                    @if (isset($expanded[$property->id]))
                        <flux:icon.chevron-up class="size-4 text-zinc-500" />
                    @else
                        <flux:icon.chevron-down class="size-4 text-zinc-500" />
                    @endif
                </div>
            </div>

            @if (isset($expanded[$property->id]))
                <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach ($property->units as $unit)
                            <a
                                wire:key="unit-{{ $unit->id }}"
                                href="{{ route('units.show', ['unit' => $unit]) }}"
                                wire:navigate
                                class="group flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition hover:border-brand-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                            >
                                <div class="relative">
                                    <x-unit-photo :unit="$unit" />

                                    <div class="absolute inset-x-2 top-2 flex flex-wrap items-start justify-between gap-1">
                                        <div class="flex flex-wrap gap-1">
                                            @unless ($unit->hasLockedDetails())
                                                <flux:badge color="amber" size="sm">{{ __('Details needed') }}</flux:badge>
                                            @endunless
                                            @if ($unit->open_concerns_count > 0)
                                                <flux:badge color="amber" size="sm">
                                                    {{ trans_choice(':count open report|:count open reports', $unit->open_concerns_count) }}
                                                </flux:badge>
                                            @endif
                                        </div>
                                        <flux:badge :color="$unit->status->color()" size="sm">{{ $unit->status->label() }}</flux:badge>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-0.5 p-3 text-center">
                                    <span class="font-medium text-zinc-900 group-hover:text-brand-600">{{ __('Unit :number', ['number' => $unit->unit_number]) }}</span>
                                    <span class="truncate text-xs text-zinc-500">
                                        @if ($unit->allows_multiple_tenants)
                                            {{ __(':taken/:capacity tenants', ['taken' => $unit->activeLeases->count(), 'capacity' => $unit->capacity()]) }}
                                        @elseif ($unit->activeLeases->isNotEmpty())
                                            {{ $unit->activeLeases->first()->tenant->name }}
                                        @else
                                            {{ __('No tenant') }}
                                        @endif
                                        &middot; &#8369;{{ number_format((float) $unit->price) }}/mo
                                    </span>
                                    @if ($unit->takenSlotCount() > $unit->capacity())
                                        <span class="text-xs text-amber-700">{{ __('Over capacity') }}</span>
                                    @endif
                                </div>
                            </a>
                        @endforeach

                        @if ($addingUnitTo !== $property->id)
                            <button
                                type="button"
                                wire:click="startAddingUnit({{ $property->id }})"
                                class="flex min-h-40 flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-300 text-sm font-medium text-zinc-600 transition hover:border-brand-500 hover:text-brand-600"
                            >
                                <flux:icon.plus class="size-6" />
                                {{ __('Add unit') }}
                            </button>
                        @endif
                    </div>

                    @if ($addingUnitTo === $property->id)
                        <form wire:submit="reviewNewUnit" class="mt-6 flex flex-col gap-4 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                            <flux:heading size="lg">{{ __('New unit in :property', ['property' => $property->name]) }}</flux:heading>

                            <x-unit-form-fields
                                :occupancy="$form->occupancy"
                                :bedrooms="$form->bedrooms"
                                :is-studio="$form->isStudio"
                                :has-shared-bathroom="$form->hasSharedBathroom"
                                :amenity-groups="$this->formAmenityGroups"
                                :selected-amenities="$form->amenityIds"
                                :amenity-limits="$this->formAmenityLimits"
                                :amenity-quantities="$form->amenityQuantities"
                                :bed-spaces="$this->formBedSpacesPreview"
                                :bed-summary="$form->bedSummary()"
                                :max-capacity="$this->formMaxCapacityPreview"
                                :max-bedrooms="$this->formMaxBedroomsPreview"
                                :max-bathrooms="$this->formMaxBathroomsPreview"
                                examples
                            />

                            <div class="flex justify-end gap-2">
                                <flux:button variant="filled" wire:click="cancelAddingUnit">{{ __('Cancel') }}</flux:button>
                                <flux:button type="submit" variant="primary">{{ __('Add unit') }}</flux:button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('No properties yet') }}</flux:heading>
            <flux:subheading>{{ __('Add your first property to start managing units and tenants.') }}</flux:subheading>
            <flux:button :href="route('setup')" variant="primary" class="mt-4" wire:navigate>
                {{ __('Add property') }}
            </flux:button>
        </div>
    @endforelse

    <x-confirm-unit-modal :floor-level="$form->floor_level" :floor-area="$form->floor_area_sqm" :bedrooms="$form->bedrooms" :bathrooms="$form->bathrooms" />

    <flux:modal name="edit-property-modal" class="max-w-lg md:min-w-lg" @close="closePropertyModal" wire:model="showPropertyModal">
        <form wire:submit="updateProperty" class="space-y-6">
            <flux:heading size="lg">{{ __('Edit property') }}</flux:heading>

            <flux:input wire:model="property_name" :label="__('Property name')" required />

            <div>
                <flux:text class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('Property type') }}</flux:text>
                <div class="mt-1 flex items-center gap-2">
                    <flux:icon.lock-closed variant="micro" class="text-zinc-500 dark:text-zinc-400" />
                    <flux:text>{{ $this->properties->firstWhere('id', $editingPropertyId)?->type->label() }}</flux:text>
                </div>
                <flux:text class="mt-1 text-xs">{{ __('The type is set when the property is added and cannot be changed.') }}</flux:text>
            </div>

            <flux:input wire:model="address_line" :label="__('Street address')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="city" :label="__('City')" required />
                <flux:input wire:model="province" :label="__('Province')" required />
            </div>

            <flux:input wire:model="postal_code" :label="__('Postal code')" required />

            <flux:input wire:model="map_url" type="url" :label="__('Map link (optional)')" placeholder="https://maps.google.com/..." />

            <flux:text class="text-xs">{{ __('Changing the address sends this property\'s live listings back for review.') }}</flux:text>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closePropertyModal">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save property') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
