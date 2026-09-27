<?php

use App\Enums\PropertyType;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Livewire\Forms\UnitForm;
use App\Models\Amenity;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use App\Rules\UniqueTeamInvitation;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Add a property')] class extends Component
{
    public UnitForm $form;

    public bool $showConfirmUnitModal = false;

    public int $step = 1;

    public ?int $propertyId = null;

    public string $name = '';

    public string $address_line = '';

    public string $city = '';

    public string $province = '';

    public string $postal_code = '';

    public string $type = PropertyType::Apartment->value;

    public string $inviteEmail = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function property(): ?Property
    {
        return $this->propertyId
            ? $this->team->properties()->find($this->propertyId)
            : null;
    }

    /**
     * @return array<int, PropertyType>
     */
    #[Computed]
    public function propertyTypes(): array
    {
        return PropertyType::cases();
    }

    public function createProperty(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'type' => ['required', Rule::enum(PropertyType::class)],
        ]);

        $property = $this->team->properties()->create($validated);

        $this->propertyId = $property->id;
        $this->step = 2;

        unset($this->property);
    }

    #[Computed]
    public function formMaxCapacityPreview(): ?int
    {
        return $this->property ? $this->form->maxCapacity($this->property->type) : null;
    }

    /**
     * @return Collection<string, EloquentCollection<int, Amenity>>
     */
    #[Computed]
    public function formAmenityGroups(): Collection
    {
        return $this->form->amenityGroups($this->team);
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
     * Validate the new unit, then ask the landlord to confirm the details
     * that will be locked once it is saved.
     */
    public function reviewNewUnit(): void
    {
        $property = $this->team->properties()->findOrFail($this->propertyId);

        $this->form->validatedAttributes($property->type, $property->id);

        $this->showConfirmUnitModal = true;
    }

    public function closeConfirmUnitModal(): void
    {
        $this->showConfirmUnitModal = false;
    }

    public function addUnit(): void
    {
        $property = $this->team->properties()->findOrFail($this->propertyId);

        $attributes = $this->form->validatedAttributes($property->type, $property->id);

        DB::transaction(function () use ($property, $attributes): void {
            $unit = $property->units()->create([...$attributes, 'status' => UnitStatus::Vacant]);

            $this->form->syncAmenities($unit);
        });

        $this->showConfirmUnitModal = false;
        $this->form->reset();

        unset($this->property);
    }

    public function continueToInvite(): void
    {
        $this->step = 3;
    }

    public function sendInvite(): void
    {
        Gate::authorize('inviteMember', $this->team);

        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation($this->team)],
        ]);

        $invitation = $this->team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'role' => TeamRole::Tenant,
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new TeamInvitationNotification($invitation));

        $this->reset('inviteEmail');

        Flux::toast(variant: 'success', text: __('Invitation sent.'));

        $this->step = 4;
    }

    public function skipInvite(): void
    {
        $this->step = 4;
    }
}; ?>

<section class="mx-auto flex w-full max-w-2xl flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Add a property') }}</flux:heading>
        <flux:subheading>{{ __('A few quick steps to get :team ready.', ['team' => $this->team->name]) }}</flux:subheading>
    </div>

    <ol class="flex items-center gap-4 text-sm">
        @foreach ([1 => __('Property'), 2 => __('Units'), 3 => __('Invite tenant'), 4 => __('Done')] as $number => $label)
            <li class="flex items-center gap-2 {{ $step >= $number ? 'text-brand-500' : 'text-zinc-400 dark:text-zinc-600' }}">
                <span class="flex size-6 items-center justify-center rounded-full border {{ $step >= $number ? 'border-brand-500 bg-brand-500/10' : 'border-zinc-300 dark:border-zinc-700' }} text-xs font-medium">
                    {{ $number }}
                </span>
                <span class="hidden font-medium sm:inline">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    @if ($step === 1)
        <form wire:submit="createProperty" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Property name')" placeholder="Sunrise Apartments" required autofocus />

            <flux:select wire:model="type" :label="__('Property type')" :description="__('This cannot be changed after you continue.')" required>
                @foreach ($this->propertyTypes as $option)
                    <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="address_line" :label="__('Street address')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="city" :label="__('City')" required />
                <flux:input wire:model="province" :label="__('Province')" required />
            </div>

            <flux:input wire:model="postal_code" :label="__('Postal code')" required />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Continue') }}</flux:button>
            </div>
        </form>
    @elseif ($step === 2)
        <div class="flex flex-col gap-6">
            @if ($this->property->units->isNotEmpty())
                <ul class="grid gap-2">
                    @foreach ($this->property->units as $unit)
                        <li wire:key="unit-{{ $unit->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-zinc-700">
                            <span class="font-medium">{{ __('Unit :number', ['number' => $unit->unit_number]) }}</span>
                            <span class="text-zinc-500 dark:text-zinc-400">
                                {{ $unit->floor_level }} &middot;
                                {{ $unit->bedrooms === 0 ? __('Studio') : $unit->bedrooms.' '.Str::plural('bed', $unit->bedrooms) }} &middot;
                                {{ $unit->bathrooms === 0 ? __('Shared bath') : $unit->bathrooms.' '.Str::plural('bath', $unit->bathrooms) }} &middot;
                                {{ Unit::formatFloorArea((float) $unit->floor_area_sqm) }} m²
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <form wire:submit="reviewNewUnit" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('Add a unit') }}</flux:heading>

                <x-unit-form-fields
                    :occupancy="$form->occupancy"
                    :bedrooms="$form->bedrooms"
                    :amenity-groups="$this->formAmenityGroups"
                    :selected-amenities="$form->amenityIds"
                    :bed-spaces="$this->formBedSpacesPreview"
                    :max-capacity="$this->formMaxCapacityPreview"
                    :max-bedrooms="$this->formMaxBedroomsPreview"
                    :max-bathrooms="$this->formMaxBathroomsPreview"
                    examples
                />

                <div class="flex justify-end">
                    <flux:button type="submit" variant="filled">{{ __('Add unit') }}</flux:button>
                </div>
            </form>

            <div class="flex justify-end">
                <flux:button
                    variant="primary"
                    wire:click="continueToInvite"
                    :disabled="$this->property->units->isEmpty()"
                >
                    {{ __('Continue') }}
                </flux:button>
            </div>

            <x-confirm-unit-modal :floor-level="$form->floor_level" :floor-area="$form->floor_area_sqm" :bedrooms="$form->bedrooms" :bathrooms="$form->bathrooms" />
        </div>
    @elseif ($step === 3)
        <form wire:submit="sendInvite" class="flex flex-col gap-6">
            <flux:text>{{ __("Invite your first tenant by email. They'll be able to set their own password once they accept.") }}</flux:text>

            <flux:input wire:model="inviteEmail" type="email" :label="__('Tenant email address')" placeholder="tenant@example.com" required autofocus />

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="skipInvite">{{ __('Skip for now') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Send invite') }}</flux:button>
            </div>
        </form>
    @elseif ($step === 4)
        <div class="flex flex-col items-start gap-6 rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
            <span class="flex size-11 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
                <flux:icon name="check-circle" class="size-6 text-brand-500" />
            </span>

            <div>
                <flux:heading size="lg">{{ __("You're all set") }}</flux:heading>
                <flux:subheading>{{ __(':name has been added to :team.', ['name' => $this->property->name, 'team' => $this->team->name]) }}</flux:subheading>
            </div>

            <div class="flex gap-2">
                <flux:button :href="route('properties')" variant="filled" wire:navigate>{{ __('View properties') }}</flux:button>
                <flux:button :href="route('dashboard')" variant="primary" wire:navigate>{{ __('Go to dashboard') }}</flux:button>
            </div>
        </div>
    @endif
</section>
