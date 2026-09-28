<?php

use App\Livewire\Forms\UnitForm;
use App\Models\Amenity;
use App\Models\Team;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Unit details')] class extends Component
{
    public UnitForm $form;

    #[Locked]
    public int $unitId;

    public bool $showDeleteUnitModal = false;

    public function mount(int $unit): void
    {
        $this->unitId = $unit;

        Gate::authorize('viewInventory', $this->unit);

        $this->form->fillFromUnit($this->unit);
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Resolved within the current team on every request, so a unit from
     * another landlord 404s.
     */
    #[Computed]
    public function unit(): Unit
    {
        return Unit::query()
            ->whereHas('property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with('property')
            ->findOrFail($this->unitId);
    }

    #[Computed]
    public function formMaxCapacityPreview(): ?int
    {
        return $this->form->maxCapacity($this->unit->property->type, $this->unit);
    }

    /**
     * @return Collection<string, EloquentCollection<int, Amenity>>
     */
    #[Computed]
    public function formAmenityGroups(): Collection
    {
        return $this->form->amenityGroups($this->team, $this->unit);
    }

    /**
     * The most of each ticked amenity this unit can have.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function formAmenityLimits(): array
    {
        return $this->form->amenityQuantityLimits($this->unit->property->type, $this->unit);
    }

    /**
     * Why each ticked amenity is limited to its number.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function formAmenityLimitReasons(): array
    {
        return $this->form->amenityLimitReasons($this->unit->property->type, $this->unit);
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
        $this->form->clampTenantLimit($this->formMaxCapacityPreview, $this->unit->takenSlotCount());
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
        if (Str::startsWith($property, 'form.amenityQuantities.')) {
            $this->form->clampAmenityQuantity(Str::after($property, 'form.amenityQuantities.'), $this->unit->property->type, $this->unit);
        }

        if (Str::startsWith($property, ['form.amenityIds', 'form.amenityQuantities'])) {
            unset($this->formMaxCapacityPreview, $this->formBedSpacesPreview);

            $this->form->followMaxCapacity(
                $this->maxCapacityBeforeBedsChanged,
                $this->formMaxCapacityPreview,
                $this->unit->takenSlotCount(),
            );
        }
    }

    public function updateUnit(): void
    {
        $unit = $this->unit;

        $attributes = $this->form->validatedAttributes($unit->property->type, $unit->property_id, $unit);

        $priceChanged = round((float) $unit->price, 2) !== round((float) $attributes['price'], 2);

        DB::transaction(function () use ($unit, $attributes, $priceChanged): void {
            $unit->update($attributes);
            $this->form->syncAmenities($unit);

            if ($priceChanged) {
                $unit->splitRentAmongActiveTenants();
            }
        });

        unset($this->unit);
        $this->form->fillFromUnit($this->unit);

        Flux::toast(variant: 'success', text: __('Unit saved.'));
    }

    public function confirmDeleteUnit(): void
    {
        if (! $this->unit->canBeDeleted()) {
            Flux::toast(variant: 'danger', text: __('This unit has had a tenant, a reservation or a listing, so it cannot be deleted.'));

            return;
        }

        $this->showDeleteUnitModal = true;
    }

    public function closeDeleteUnitModal(): void
    {
        $this->showDeleteUnitModal = false;
    }

    public function deleteUnit(): void
    {
        $unit = $this->unit;

        if (! $unit->canBeDeleted()) {
            $this->closeDeleteUnitModal();
            Flux::toast(variant: 'danger', text: __('This unit has had a tenant, a reservation or a listing, so it cannot be deleted.'));

            return;
        }

        $photoPaths = ($unit->listing?->photos()->pluck('path') ?? collect())
            ->when($unit->photo_path, fn ($paths) => $paths->push($unit->photo_path));

        $unit->delete();

        Storage::disk(config('filesystems.media_disk'))->delete($photoPaths->all());

        Flux::toast(variant: 'success', text: __('Unit deleted.'));

        $this->redirectRoute('properties', navigate: true);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    @php($unit = $this->unit)

    <x-unit-header :unit="$unit" current="details" />

    <form wire:submit="updateUnit" class="flex max-w-3xl flex-col gap-4">
        @unless ($unit->hasLockedDetails())
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Add this unit\'s floor area and rooms') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Its tenant capacity is worked out from its floor area. Check these details carefully: once saved, they cannot be changed.') }}</flux:callout.text>
            </flux:callout>
        @endunless

        <x-unit-form-fields
            :occupancy="$form->occupancy"
            :bedrooms="$form->bedrooms"
            :is-studio="$form->isStudio"
            :has-shared-bathroom="$form->hasSharedBathroom"
            :amenity-groups="$this->formAmenityGroups"
            :selected-amenities="$form->amenityIds"
            :amenity-limits="$this->formAmenityLimits"
            :amenity-limit-reasons="$this->formAmenityLimitReasons"
            :amenity-quantities="$form->amenityQuantities"
            :bed-spaces="$this->formBedSpacesPreview"
            :bed-summary="$form->bedSummary()"
            :max-capacity="$this->formMaxCapacityPreview"
            :max-bedrooms="$this->formMaxBedroomsPreview"
            :max-bathrooms="$this->formMaxBathroomsPreview"
            :locked-unit="$unit->hasLockedDetails() ? $unit : null"
        />

        <div class="flex flex-wrap items-center justify-between gap-2">
            <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDeleteUnit">
                {{ __('Delete unit') }}
            </flux:button>

            <flux:button type="submit" variant="primary">{{ __('Save unit') }}</flux:button>
        </div>
    </form>

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
</section>
