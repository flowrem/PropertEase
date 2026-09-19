<?php

use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Properties')] class extends Component
{
    public ?int $addingUnitTo = null;

    public string $unit_number = '';

    public string $floor_level = '';

    public int $bedrooms = 1;

    public int $bathrooms = 1;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Property>
     */
    #[Computed]
    public function properties(): \Illuminate\Support\Collection
    {
        return $this->team->properties()->with('units')->latest()->get();
    }

    public function startAddingUnit(int $propertyId): void
    {
        $this->addingUnitTo = $propertyId;
        $this->reset('unit_number', 'floor_level');
        $this->bedrooms = 1;
        $this->bathrooms = 1;
    }

    public function cancelAddingUnit(): void
    {
        $this->addingUnitTo = null;
    }

    public function addUnit(): void
    {
        $property = $this->team->properties()->findOrFail($this->addingUnitTo);

        $validated = $this->validate([
            'unit_number' => [
                'required', 'string', 'max:255',
                Rule::unique('units', 'unit_number')->where('property_id', $property->id),
            ],
            'floor_level' => ['nullable', 'string', 'max:255'],
            'bedrooms' => ['required', 'integer', 'min:0', 'max:20'],
            'bathrooms' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $property->units()->create([
            ...$validated,
            'status' => UnitStatus::Vacant,
        ]);

        $this->addingUnitTo = null;
        unset($this->properties);
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
        <div wire:key="property-{{ $property->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center justify-between border-b border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <flux:heading>{{ $property->name }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ $property->type->label() }} &middot; {{ $property->address_line }}, {{ $property->city }}, {{ $property->province }}
                    </flux:text>
                </div>
                <flux:badge color="zinc">{{ $property->units->count() }} {{ $property->units->count() === 1 ? 'unit' : 'units' }}</flux:badge>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($property->units as $unit)
                    <div wire:key="unit-{{ $unit->id }}" class="flex items-center justify-between px-4 py-3 text-sm">
                        <span class="font-medium">{{ __('Unit :number', ['number' => $unit->unit_number]) }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">
                            {{ $unit->bedrooms }} {{ $unit->bedrooms === 1 ? 'bed' : 'beds' }} &middot;
                            {{ $unit->bathrooms }} {{ $unit->bathrooms === 1 ? 'bath' : 'baths' }}
                        </span>
                        <flux:badge :color="$unit->status === UnitStatus::Vacant ? 'lime' : ($unit->status === UnitStatus::Occupied ? 'blue' : 'amber')" size="sm">
                            {{ $unit->status->label() }}
                        </flux:badge>
                    </div>
                @empty
                    <p class="px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No units added yet.') }}</p>
                @endforelse
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                @if ($addingUnitTo === $property->id)
                    <form wire:submit="addUnit" class="flex flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="unit_number" :label="__('Unit number')" placeholder="101" required autofocus />
                            <flux:input wire:model="floor_level" :label="__('Floor level')" placeholder="1st floor" />
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:input wire:model="bedrooms" type="number" min="0" max="20" :label="__('Bedrooms')" required />
                            <flux:input wire:model="bathrooms" type="number" min="0" max="20" :label="__('Bathrooms')" required />
                        </div>

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
</section>
