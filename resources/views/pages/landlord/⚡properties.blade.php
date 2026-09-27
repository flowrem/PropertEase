<?php

use App\Enums\ConcernStatus;
use App\Enums\ListingStatus;
use App\Enums\UnitStatus;
use App\Livewire\Forms\UnitForm;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public ?int $editingUnitId = null;

    public bool $showDeleteUnitModal = false;

    public ?int $deletingUnitId = null;

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
                ->withCount(['heldReservations', 'concerns as open_concerns_count' => fn ($concerns) => $concerns
                    ->where('concerns.status', '!=', ConcernStatus::Resolved->value)]),
            ])
            ->latest()
            ->get();
    }

    /**
     * The property whose unit is on the form, when a unit is being added or edited.
     */
    #[Computed]
    public function formProperty(): ?Property
    {
        $propertyId = $this->addingUnitTo ?? $this->editingUnit?->property_id;

        return $propertyId ? $this->team->properties()->find($propertyId) : null;
    }

    #[Computed]
    public function editingUnit(): ?Unit
    {
        return $this->editingUnitId ? $this->teamUnit($this->editingUnitId) : null;
    }

    #[Computed]
    public function formMaxCapacityPreview(): ?int
    {
        return $this->formProperty
            ? $this->form->maxCapacity($this->formProperty->type, $this->editingUnit)
            : null;
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
        $this->form->clampTenantLimit($this->formMaxCapacityPreview, $this->editingUnit?->takenSlotCount() ?? 0);
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

        $this->editingUnitId = null;
        $this->addingUnitTo = $property->id;
        $this->expanded[$property->id] = true;
        $this->form->reset();
        $this->resetValidation();
        unset($this->formProperty, $this->editingUnit);
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

        $property->units()->create([
            ...$this->form->validatedAttributes($property->type, $property->id),
            'status' => UnitStatus::Vacant,
        ]);

        $this->showConfirmUnitModal = false;
        $this->addingUnitTo = null;
        unset($this->properties);

        Flux::toast(variant: 'success', text: __('Unit added.'));
    }

    public function startEditingUnit(int $unitId): void
    {
        $unit = $this->teamUnit($unitId);

        $this->addingUnitTo = null;
        $this->editingUnitId = $unit->id;
        $this->expanded[$unit->property_id] = true;
        $this->resetValidation();
        $this->form->fillFromUnit($unit);
        unset($this->formProperty, $this->editingUnit);
    }

    public function cancelEditingUnit(): void
    {
        $this->editingUnitId = null;
    }

    public function updateUnit(): void
    {
        $unit = $this->teamUnit($this->editingUnitId);

        $attributes = $this->form->validatedAttributes($unit->property->type, $unit->property_id, $unit);

        $priceChanged = round((float) $unit->price, 2) !== round((float) $attributes['price'], 2);

        $unit->update($attributes);

        if ($priceChanged) {
            $unit->splitRentAmongActiveTenants();
        }

        $this->editingUnitId = null;
        unset($this->properties, $this->editingUnit);

        Flux::toast(variant: 'success', text: __('Unit saved.'));
    }

    public function confirmDeleteUnit(int $unitId): void
    {
        $unit = $this->teamUnit($unitId);

        if (! $unit->canBeDeleted()) {
            Flux::toast(variant: 'danger', text: __('This unit has had a tenant, a reservation or a listing, so it cannot be deleted.'));

            return;
        }

        $this->deletingUnitId = $unit->id;
        $this->showDeleteUnitModal = true;
    }

    public function closeDeleteUnitModal(): void
    {
        $this->showDeleteUnitModal = false;
        $this->deletingUnitId = null;
    }

    public function deleteUnit(): void
    {
        $unit = $this->teamUnit($this->deletingUnitId);

        if (! $unit->canBeDeleted()) {
            $this->closeDeleteUnitModal();
            Flux::toast(variant: 'danger', text: __('This unit has had a tenant, a reservation or a listing, so it cannot be deleted.'));

            return;
        }

        $photoPaths = $unit->listing?->photos()->pluck('path') ?? collect();

        $unit->delete();

        Storage::disk(config('filesystems.media_disk'))->delete($photoPaths->all());

        $this->closeDeleteUnitModal();
        $this->editingUnitId = null;
        unset($this->properties, $this->editingUnit);

        Flux::toast(variant: 'success', text: __('Unit deleted.'));
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

    /**
     * Find a unit belonging to the current team, or fail with a 404.
     */
    protected function teamUnit(?int $unitId): Unit
    {
        return Unit::with('property')
            ->whereHas('property', fn ($query) => $query->where('team_id', $this->team->id))
            ->findOrFail($unitId);
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
                        <flux:icon.chevron-up class="size-4 text-zinc-400" />
                    @else
                        <flux:icon.chevron-down class="size-4 text-zinc-400" />
                    @endif
                </div>
            </div>

            @if (isset($expanded[$property->id]))
                <div class="divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($property->units as $unit)
                        <div wire:key="unit-{{ $unit->id }}" class="px-4 py-3 text-sm">
                            @if ($editingUnitId === $unit->id)
                                <form wire:submit="updateUnit" class="flex flex-col gap-4">
                                    @unless ($unit->hasLockedDetails())
                                        <flux:callout variant="warning" icon="exclamation-triangle">
                                            <flux:callout.heading>{{ __('Add this unit\'s floor area and rooms') }}</flux:callout.heading>
                                            <flux:callout.text>{{ __('Its tenant capacity is worked out from its floor area. Check these details carefully: once saved, they cannot be changed.') }}</flux:callout.text>
                                        </flux:callout>
                                    @endunless

                                    <x-unit-form-fields
                                        :occupancy="$form->occupancy"
                                        :max-capacity="$this->formMaxCapacityPreview"
                                        :max-bedrooms="$this->formMaxBedroomsPreview"
                                        :max-bathrooms="$this->formMaxBathroomsPreview"
                                        :locked-unit="$unit->hasLockedDetails() ? $unit : null"
                                    />

                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDeleteUnit({{ $unit->id }})">
                                            {{ __('Delete unit') }}
                                        </flux:button>

                                        <div class="flex gap-2">
                                            <flux:button variant="filled" size="sm" wire:click="cancelEditingUnit">{{ __('Cancel') }}</flux:button>
                                            <flux:button type="submit" variant="primary" size="sm">{{ __('Save unit') }}</flux:button>
                                        </div>
                                    </div>
                                </form>
                            @else
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <span class="font-medium">{{ __('Unit :number', ['number' => $unit->unit_number]) }}</span>
                                        <span class="text-zinc-500 dark:text-zinc-400">
                                            @if ($unit->floor_level)
                                                &middot; {{ $unit->floor_level }}
                                            @endif
                                            &middot; {{ $unit->bedrooms === 0 ? __('Studio') : $unit->bedrooms.' '.Str::plural('bed', $unit->bedrooms) }}
                                            &middot; {{ $unit->bathrooms === 0 ? __('Shared bath') : $unit->bathrooms.' '.Str::plural('bath', $unit->bathrooms) }}
                                            @if ($unit->hasLockedDetails())
                                                &middot; {{ Unit::formatFloorArea((float) $unit->floor_area_sqm) }} m²
                                            @endif
                                            &middot; &#8369;{{ number_format((float) $unit->price, 2) }}/mo
                                            @if ($unit->allows_multiple_tenants)
                                                &middot; {{ __('Multiple tenants') }}
                                                @if ($unit->tenant_limit !== null)
                                                    ({{ $unit->activeLeases->count() }}/{{ $unit->capacity() }})
                                                @endif
                                            @endif
                                        </span>

                                        @if ($unit->activeLeases->isNotEmpty())
                                            <flux:text class="block text-zinc-500 dark:text-zinc-400">
                                                {{ $unit->activeLeases->pluck('tenant.name')->implode(', ') }}
                                                @if ($unit->activeLeases->count() > 1)
                                                    ({{ __('₱:amount each', ['amount' => number_format($unit->rentSharePerTenant(), 2)]) }})
                                                @endif
                                            </flux:text>
                                        @endif

                                        @if ($unit->takenSlotCount() > $unit->capacity())
                                            <flux:text class="block text-amber-700 dark:text-amber-400">
                                                {{ __('More tenants than its floor area allows (:max). No new tenants until it fits.', ['max' => $unit->capacity()]) }}
                                            </flux:text>
                                        @endif
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
                                        @unless ($unit->hasLockedDetails())
                                            <flux:badge color="amber" size="sm">{{ __('Details needed') }}</flux:badge>
                                        @endunless

                                        @if ($unit->activeLeases->count() === 1 && $unit->activeLeases->first()->currentInvoice)
                                            @php($invoice = $unit->activeLeases->first()->currentInvoice)
                                            <flux:badge :color="$invoice->status->color()" size="sm">
                                                {{ $invoice->status->label() }}
                                                &middot; {{ __('due') }} {{ $invoice->due_date->format('M j') }}
                                            </flux:badge>
                                        @endif

                                        @if ($unit->open_concerns_count > 0)
                                            <a href="{{ route('inbox') }}" wire:navigate>
                                                <flux:badge color="amber" size="sm">
                                                    {{ $unit->open_concerns_count }} {{ Str::plural('open concern', $unit->open_concerns_count) }}
                                                </flux:badge>
                                            </a>
                                        @endif

                                        <flux:badge :color="$unit->status->color()" size="sm">
                                            {{ $unit->status->label() }}
                                        </flux:badge>

                                        <flux:button variant="ghost" size="sm" icon="pencil" :aria-label="__('Edit unit')" wire:click="startEditingUnit({{ $unit->id }})" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No units added yet.') }}</p>
                    @endforelse
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                    @if ($addingUnitTo === $property->id)
                        <form wire:submit="reviewNewUnit" class="flex flex-col gap-4">
                            <x-unit-form-fields
                                :occupancy="$form->occupancy"
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
                    @else
                        <flux:button variant="filled" size="sm" icon="plus" wire:click="startAddingUnit({{ $property->id }})">
                            {{ __('Add unit') }}
                        </flux:button>
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

    <flux:modal name="delete-unit-modal" class="max-w-md md:min-w-md" @close="closeDeleteUnitModal" wire:model="showDeleteUnitModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Delete unit') }}</flux:heading>
                <flux:text>{{ __('This unit has never had a tenant, a reservation or a listing, so it can be removed. Any draft listing for it is deleted too.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeDeleteUnitModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteUnit">{{ __('Delete unit') }}</flux:button>
            </div>
        </div>
    </flux:modal>

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
