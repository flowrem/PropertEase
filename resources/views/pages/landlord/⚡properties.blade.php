<?php

use App\Enums\ConcernStatus;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Team;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Properties')] class extends Component
{
    /**
     * IDs of properties currently expanded in the UI.
     *
     * @var array<int, true>
     */
    public array $expanded = [];

    public ?int $addingUnitTo = null;

    public ?int $editingUnitId = null;

    public string $unit_number = '';

    public string $floor_level = '';

    public int $bedrooms = 1;

    public int $bathrooms = 1;

    public string $occupancy = 'single';

    public ?int $tenant_limit = null;

    public string $price = '';

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
                ->withCount(['concerns as open_concerns_count' => fn ($concerns) => $concerns
                    ->where('concerns.status', '!=', ConcernStatus::Resolved->value)]),
            ])
            ->latest()
            ->get();
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
        $this->editingUnitId = null;
        $this->addingUnitTo = $propertyId;
        $this->expanded[$propertyId] = true;
        $this->reset('unit_number', 'floor_level', 'bedrooms', 'bathrooms', 'occupancy', 'tenant_limit', 'price');
    }

    public function cancelAddingUnit(): void
    {
        $this->addingUnitTo = null;
    }

    public function addUnit(): void
    {
        $property = $this->team->properties()->findOrFail($this->addingUnitTo);

        $validated = $this->validate($this->unitRules($property->id));

        $property->units()->create([
            ...$this->unitAttributes($validated),
            'status' => UnitStatus::Vacant,
        ]);

        $this->addingUnitTo = null;
        unset($this->properties);
    }

    public function startEditingUnit(int $unitId): void
    {
        $unit = $this->teamUnit($unitId);

        $this->addingUnitTo = null;
        $this->editingUnitId = $unit->id;
        $this->expanded[$unit->property_id] = true;
        $this->unit_number = $unit->unit_number;
        $this->floor_level = (string) $unit->floor_level;
        $this->bedrooms = $unit->bedrooms;
        $this->bathrooms = $unit->bathrooms;
        $this->occupancy = $unit->allows_multiple_tenants ? 'multiple' : 'single';
        $this->tenant_limit = $unit->tenant_limit;
        $this->price = (string) $unit->price;
    }

    public function cancelEditingUnit(): void
    {
        $this->editingUnitId = null;
    }

    public function updateUnit(): void
    {
        $unit = $this->teamUnit($this->editingUnitId);

        $validated = $this->validate($this->unitRules($unit->property_id, ignoring: $unit));

        $priceChanged = round((float) $unit->price, 2) !== round((float) $validated['price'], 2);

        $unit->update($this->unitAttributes($validated));

        if ($priceChanged) {
            $unit->splitRentAmongActiveTenants();
        }

        $this->editingUnitId = null;
        unset($this->properties);
    }

    /**
     * Validation rules shared by the add and edit unit forms.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function unitRules(int $propertyId, ?Unit $ignoring = null): array
    {
        return [
            'unit_number' => [
                'required', 'string', 'max:255',
                Rule::unique('units', 'unit_number')->where('property_id', $propertyId)->ignore($ignoring),
            ],
            'floor_level' => ['nullable', 'string', 'max:255'],
            'bedrooms' => ['required', 'integer', 'min:0', 'max:20'],
            'bathrooms' => ['required', 'integer', 'min:0', 'max:20'],
            'occupancy' => ['required', Rule::in(['single', 'multiple'])],
            'tenant_limit' => [$this->occupancy === 'multiple' ? 'required' : 'nullable', 'integer', 'min:2', 'max:50'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }

    /**
     * Map validated form input onto unit attributes.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function unitAttributes(array $validated): array
    {
        $allowsMultipleTenants = $validated['occupancy'] === 'multiple';

        return [
            'unit_number' => $validated['unit_number'],
            'floor_level' => $validated['floor_level'],
            'bedrooms' => $validated['bedrooms'],
            'bathrooms' => $validated['bathrooms'],
            'allows_multiple_tenants' => $allowsMultipleTenants,
            'tenant_limit' => $allowsMultipleTenants ? $validated['tenant_limit'] : null,
            'price' => $validated['price'],
        ];
    }

    /**
     * Find a unit belonging to the current team, or fail with a 404.
     */
    protected function teamUnit(int $unitId): Unit
    {
        return Unit::whereHas('property', fn ($query) => $query->where('team_id', $this->team->id))
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
                                    <x-unit-form-fields :occupancy="$occupancy" />

                                    <div class="flex justify-end gap-2">
                                        <flux:button variant="filled" size="sm" wire:click="cancelEditingUnit">{{ __('Cancel') }}</flux:button>
                                        <flux:button type="submit" variant="primary" size="sm">{{ __('Save unit') }}</flux:button>
                                    </div>
                                </form>
                            @else
                                <div class="flex items-center justify-between gap-4">
                                    <div>
                                        <span class="font-medium">{{ __('Unit :number', ['number' => $unit->unit_number]) }}</span>
                                        <span class="text-zinc-500 dark:text-zinc-400">
                                            &middot; {{ $unit->bedrooms }} {{ Str::plural('bed', $unit->bedrooms) }}
                                            &middot; {{ $unit->bathrooms }} {{ Str::plural('bath', $unit->bathrooms) }}
                                            &middot; &#8369;{{ number_format((float) $unit->price, 2) }}/mo
                                            @if ($unit->allows_multiple_tenants)
                                                &middot; {{ __('Multiple tenants') }}
                                                @if ($unit->tenant_limit !== null)
                                                    ({{ $unit->activeLeases->count() }}/{{ $unit->tenant_limit }})
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
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2">
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
                        <form wire:submit="addUnit" class="flex flex-col gap-4">
                            <x-unit-form-fields :occupancy="$occupancy" examples />

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
</section>
