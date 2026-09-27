<?php

use App\Enums\ConditionCheckKind;
use App\Enums\ItemCondition;
use App\Enums\UnitItemType;
use App\Models\ConditionCheck;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitItem;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Unit inventory')] class extends Component
{
    #[Locked]
    public int $unitId;

    public ?int $editingItemId = null;

    public string $itemName = '';

    public string $itemType = '';

    public string $itemInstalledAt = '';

    public ?int $copyFromUnitId = null;

    public bool $recordingCheck = false;

    public string $checkKind = '';

    public string $checkNotes = '';

    /**
     * The condition picked for each item on the check, keyed by item ID.
     *
     * @var array<int|string, string>
     */
    public array $conditions = [];

    /**
     * @var array<int|string, string>
     */
    public array $remarks = [];

    public function mount(int $unit): void
    {
        $this->unitId = $unit;

        Gate::authorize('viewInventory', $this->unit);
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
    public function canManage(): bool
    {
        return Gate::allows('manageInventory', $this->unit);
    }

    /**
     * @return EloquentCollection<int, UnitItem>
     */
    #[Computed]
    public function items(): EloquentCollection
    {
        return $this->unit->items()->active()->with('amenity')->orderBy('item_type')->orderBy('name')->get();
    }

    /**
     * The team's other units that have items to copy.
     *
     * @return EloquentCollection<int, Unit>
     */
    #[Computed]
    public function otherUnits(): EloquentCollection
    {
        return Unit::query()
            ->whereHas('property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->whereKeyNot($this->unitId)
            ->whereHas('items', fn ($items) => $items->whereNull('removed_at'))
            ->with('property')
            ->orderBy('unit_number')
            ->get();
    }

    /**
     * @return EloquentCollection<int, ConditionCheck>
     */
    #[Computed]
    public function checks(): EloquentCollection
    {
        return $this->unit->conditionChecks()
            ->with(['items.unitItem', 'checker', 'lease.tenant'])
            ->latest('checked_at')
            ->latest('id')
            ->get();
    }

    #[Computed]
    public function pendingMoveInCheck(): ?ConditionCheck
    {
        return $this->unit->pendingMoveInCheck();
    }

    public function saveItem(): void
    {
        Gate::authorize('manageInventory', $this->unit);

        $validated = $this->validate([
            'itemName' => ['required', 'string', 'max:80'],
            'itemType' => ['required', Rule::enum(UnitItemType::class)],
            'itemInstalledAt' => ['nullable', 'date', 'before_or_equal:today'],
        ], attributes: [
            'itemName' => __('name'),
            'itemType' => __('type'),
            'itemInstalledAt' => __('installed on'),
        ]);

        $attributes = [
            'name' => trim($validated['itemName']),
            'item_type' => $validated['itemType'],
            'installed_at' => $validated['itemInstalledAt'] ?: null,
        ];

        if ($this->editingItemId) {
            $this->unit->items()->active()->findOrFail($this->editingItemId)->update($attributes);
        } else {
            $this->unit->items()->create($attributes);
        }

        $wasEditing = $this->editingItemId !== null;

        $this->cancelEditingItem();
        unset($this->items);

        Flux::toast(variant: 'success', text: $wasEditing ? __('Item updated.') : __('Item added.'));
    }

    public function editItem(int $itemId): void
    {
        Gate::authorize('manageInventory', $this->unit);

        $item = $this->unit->items()->active()->findOrFail($itemId);

        $this->editingItemId = $item->id;
        $this->itemName = $item->name;
        $this->itemType = $item->item_type->value;
        $this->itemInstalledAt = $item->installed_at?->toDateString() ?? '';
        $this->resetValidation();
    }

    public function cancelEditingItem(): void
    {
        $this->reset('editingItemId', 'itemName', 'itemType', 'itemInstalledAt');
        $this->resetValidation();
    }

    public function removeItem(int $itemId): void
    {
        Gate::authorize('manageInventory', $this->unit);

        $this->unit->items()->active()->findOrFail($itemId)->remove();

        if ($this->editingItemId === $itemId) {
            $this->cancelEditingItem();
        }

        unset($this->items);

        Flux::toast(variant: 'success', text: __('Item removed. Past checks still show it.'));
    }

    /**
     * Add one item per piece of each amenity the unit has, like "Double
     * deck 1" and "Double deck 2", skipping pieces already listed.
     */
    public function addFromAmenities(): void
    {
        Gate::authorize('manageInventory', $this->unit);

        $quantities = $this->unit->amenities()->pluck('amenity_unit.quantity', 'amenities.id');

        if ($quantities->isEmpty()) {
            Flux::toast(variant: 'warning', text: __('This unit has no amenities ticked yet. Tick them on the Properties page first.'));

            return;
        }

        $amenities = $this->unit->amenities()->get()->keyBy('id');
        $listed = $this->unit->items()->active()->whereNotNull('amenity_id')->get()
            ->map(fn (UnitItem $item): string => $item->amenity_id.'|'.mb_strtolower($item->name));

        $added = 0;

        foreach ($quantities as $amenityId => $quantity) {
            $amenity = $amenities[$amenityId];

            foreach (range(1, $quantity) as $piece) {
                $name = $quantity > 1 ? "{$amenity->name} {$piece}" : $amenity->name;

                if ($listed->contains($amenity->id.'|'.mb_strtolower($name))) {
                    continue;
                }

                $this->unit->items()->create([
                    'amenity_id' => $amenity->id,
                    'name' => $name,
                    'item_type' => UnitItemType::forAmenityCategory($amenity->category),
                ]);

                $added++;
            }
        }

        unset($this->items);

        Flux::toast(
            variant: 'success',
            text: $added === 0
                ? __('Every amenity is already listed.')
                : trans_choice('Added :count item from the unit\'s amenities.|Added :count items from the unit\'s amenities.', $added),
        );
    }

    /**
     * Copy another unit's items onto this one, skipping names already listed
     * here. An item keeps its amenity link only if this unit has that amenity.
     */
    public function copyFromUnit(): void
    {
        Gate::authorize('manageInventory', $this->unit);

        $this->validate([
            'copyFromUnitId' => ['required', Rule::in($this->otherUnits->modelKeys())],
        ], attributes: ['copyFromUnitId' => __('unit')]);

        $source = $this->otherUnits->find($this->copyFromUnitId);
        $ownAmenityIds = $this->unit->amenities()->pluck('amenities.id');
        $listedNames = $this->items->map(fn (UnitItem $item): string => mb_strtolower($item->name));

        $added = 0;

        foreach ($source->items()->active()->get() as $item) {
            if ($listedNames->contains(mb_strtolower($item->name))) {
                continue;
            }

            $this->unit->items()->create([
                'amenity_id' => $ownAmenityIds->contains($item->amenity_id) ? $item->amenity_id : null,
                'name' => $item->name,
                'item_type' => $item->item_type,
                'installed_at' => $item->installed_at,
            ]);

            $added++;
        }

        $this->reset('copyFromUnitId');
        unset($this->items);

        Flux::toast(
            variant: 'success',
            text: $added === 0
                ? __('Every item on that unit is already listed here.')
                : trans_choice('Copied :count item.|Copied :count items.', $added),
        );
    }

    public function startCheck(): void
    {
        Gate::authorize('recordConditionCheck', $this->unit);

        $this->reset('checkNotes', 'conditions', 'remarks');
        $this->checkKind = ConditionCheckKind::MoveIn->value;
        $this->resetValidation();
        $this->recordingCheck = true;
    }

    public function cancelCheck(): void
    {
        $this->reset('recordingCheck', 'checkKind', 'checkNotes', 'conditions', 'remarks');
        $this->resetValidation();
    }

    /**
     * Pre-fill the form with what the last check found for each item still
     * in the unit, so a repeat check only needs the changes.
     */
    public function fillFromLastCheck(): void
    {
        Gate::authorize('recordConditionCheck', $this->unit);

        $lastCheck = $this->checks->first();

        if (! $lastCheck) {
            return;
        }

        $activeIds = $this->items->modelKeys();

        foreach ($lastCheck->items as $checkedItem) {
            if (in_array($checkedItem->unit_item_id, $activeIds, true)) {
                $this->conditions[$checkedItem->unit_item_id] = $checkedItem->condition->value;
                $this->remarks[$checkedItem->unit_item_id] = (string) $checkedItem->remarks;
            }
        }
    }

    /**
     * Save a check covering every item still in the unit. Each item needs a
     * condition picked on purpose; there is no default to click through.
     */
    public function saveCheck(): void
    {
        Gate::authorize('recordConditionCheck', $this->unit);

        $items = $this->items;

        if ($items->isEmpty()) {
            $this->addError('conditions', __('List the unit\'s items before recording a check.'));

            return;
        }

        $rules = [
            'checkKind' => ['required', Rule::enum(ConditionCheckKind::class)],
            'checkNotes' => ['nullable', 'string', 'max:1000'],
        ];

        foreach ($items as $item) {
            $rules["conditions.{$item->id}"] = ['required', Rule::enum(ItemCondition::class)];
            $rules["remarks.{$item->id}"] = ['nullable', 'string', 'max:255'];
        }

        $this->validate($rules, [
            'conditions.*.required' => __('Pick a condition.'),
        ], [
            'checkKind' => __('kind of check'),
            'checkNotes' => __('notes'),
            'remarks.*' => __('remarks'),
        ]);

        DB::transaction(function () use ($items): void {
            $check = $this->unit->conditionChecks()->create([
                'kind' => $this->checkKind,
                'checked_by' => Auth::id(),
                'checked_at' => now(),
                'notes' => trim($this->checkNotes) ?: null,
            ]);

            $check->items()->createMany($items->map(fn (UnitItem $item): array => [
                'unit_item_id' => $item->id,
                'condition' => $this->conditions[$item->id],
                'remarks' => trim($this->remarks[$item->id] ?? '') ?: null,
            ])->all());
        });

        $this->cancelCheck();
        unset($this->checks, $this->pendingMoveInCheck);

        Flux::toast(variant: 'success', text: __('Check recorded.'));
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:link :href="route('properties')" wire:navigate class="text-sm">&larr; {{ __('Properties') }}</flux:link>
        <flux:heading size="xl" level="1" class="mt-2">
            {{ $this->unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $this->unit->unit_number]) }}
        </flux:heading>
        <flux:subheading>{{ __('What is in the unit, and the condition it was in at each check.') }}</flux:subheading>
    </div>

    @php($pendingCheck = $this->pendingMoveInCheck)

    @if (! $pendingCheck)
        <flux:callout icon="clipboard-document-list" color="amber">
            <flux:callout.heading>{{ __('No move-in check ready') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Record a move-in check before assigning the next tenant. Each incoming tenant gets a check of their own to acknowledge.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($pendingCheck->blockingItemCount() > 0)
        <flux:callout icon="exclamation-triangle" color="red">
            <flux:callout.heading>{{ __('Not ready for move-in') }}</flux:callout.heading>
            <flux:callout.text>
                {{ trans_choice('The move-in check on :date found :count item not working or missing. Fix it and record a new check, or the landlord must give a reason to proceed anyway.|The move-in check on :date found :count items not working or missing. Fix them and record a new check, or the landlord must give a reason to proceed anyway.', $pendingCheck->blockingItemCount(), ['date' => $pendingCheck->checked_at->format('M j, Y')]) }}
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:callout icon="check-circle" color="green">
            <flux:callout.heading>{{ __('Ready for move-in') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Move-in check recorded on :date, with nothing not working or missing. The next tenant assigned here will be asked to acknowledge it.', ['date' => $pendingCheck->checked_at->format('M j, Y')]) }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Inventory --}}
    <div class="space-y-4">
        <div>
            <flux:heading size="lg" level="2">{{ __('Inventory') }}</flux:heading>
            <flux:text>{{ __('Everything checked at move-in and move-out: the unit\'s amenities, plus things like lights, faucets and outlets.') }}</flux:text>
        </div>

        @if ($this->canManage)
            <div class="flex flex-wrap items-end gap-3">
                <flux:button icon="sparkles" wire:click="addFromAmenities">{{ __('Add from amenities') }}</flux:button>

                @if ($this->otherUnits->isNotEmpty())
                    <form wire:submit="copyFromUnit" class="flex flex-wrap items-end gap-2">
                        <flux:select wire:model="copyFromUnitId" :label="__('Copy from another unit')" :placeholder="__('Choose a unit')" class="min-w-56">
                            @foreach ($this->otherUnits as $otherUnit)
                                <flux:select.option value="{{ $otherUnit->id }}">
                                    {{ $otherUnit->property->name }} &mdash; {{ __('Unit :number', ['number' => $otherUnit->unit_number]) }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="submit" icon="document-duplicate">{{ __('Copy') }}</flux:button>
                    </form>
                @endif
            </div>
            <flux:error name="copyFromUnitId" />

            <form wire:submit="saveItem" class="grid gap-3 rounded-lg border border-zinc-200 p-4 sm:grid-cols-[1fr_12rem_11rem_auto] sm:items-end dark:border-zinc-700">
                <flux:input wire:model="itemName" :label="$editingItemId ? __('Edit item') : __('Add an item')" :placeholder="__('Ceiling light, bedroom')" maxlength="80" required />

                <flux:select wire:model="itemType" :label="__('Type')" :placeholder="__('Choose a type')" required>
                    @foreach (UnitItemType::cases() as $type)
                        <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="itemInstalledAt" type="date" max="{{ today()->toDateString() }}" :label="__('Installed on (optional)')" />

                <div class="flex gap-2">
                    @if ($editingItemId)
                        <flux:button variant="filled" wire:click="cancelEditingItem">{{ __('Cancel') }}</flux:button>
                    @endif
                    <flux:button type="submit" variant="primary" :icon="$editingItemId ? null : 'plus'">
                        {{ $editingItemId ? __('Save') : __('Add') }}
                    </flux:button>
                </div>
            </form>
        @else
            <flux:text class="text-zinc-400">{{ __('You can view the inventory and record checks. Ask your landlord or a manager to change the item list.') }}</flux:text>
        @endif

        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->items as $item)
                <div wire:key="item-{{ $item->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:text class="font-medium text-zinc-800 dark:text-white">{{ $item->name }}</flux:text>
                        <flux:badge size="sm" color="zinc">{{ $item->item_type->label() }}</flux:badge>
                        @if ($item->amenity_id)
                            <flux:badge size="sm" color="sky">{{ __('Amenity') }}</flux:badge>
                        @endif
                        @if ($item->installed_at)
                            <flux:text class="text-xs">{{ __('Installed :date', ['date' => $item->installed_at->format('M j, Y')]) }}</flux:text>
                        @endif
                    </div>

                    @if ($this->canManage)
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="editItem({{ $item->id }})" :aria-label="__('Edit :item', ['item' => $item->name])" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeItem({{ $item->id }})" :aria-label="__('Remove :item', ['item' => $item->name])" />
                        </div>
                    @endif
                </div>
            @empty
                <flux:text class="px-4 py-3 text-zinc-400">
                    {{ __('No items listed yet. Start with "Add from amenities", then add the rest.') }}
                </flux:text>
            @endforelse
        </div>
    </div>

    {{-- Condition checks --}}
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2">{{ __('Condition checks') }}</flux:heading>
                <flux:text>{{ __('Go through every item and record what you find. Move-in checks decide whether a tenant can be assigned.') }}</flux:text>
            </div>

            @unless ($recordingCheck)
                <flux:button variant="primary" icon="clipboard-document-check" wire:click="startCheck" :disabled="$this->items->isEmpty()">
                    {{ __('Record a check') }}
                </flux:button>
            @endunless
        </div>

        @if ($recordingCheck)
            <form wire:submit="saveCheck" class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <flux:radio.group wire:model="checkKind" :label="__('Kind of check')" variant="segmented">
                        @foreach (ConditionCheckKind::cases() as $kind)
                            <flux:radio value="{{ $kind->value }}">{{ $kind->label() }}</flux:radio>
                        @endforeach
                    </flux:radio.group>

                    @if ($this->checks->isNotEmpty())
                        <flux:button size="sm" icon="arrow-path" wire:click="fillFromLastCheck">{{ __('Start from the last check') }}</flux:button>
                    @endif
                </div>

                <flux:error name="conditions" />

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->items as $item)
                        <div wire:key="check-item-{{ $item->id }}" class="grid gap-2 py-3 sm:grid-cols-[1fr_11rem_1fr] sm:items-start">
                            <div>
                                <flux:text class="font-medium text-zinc-800 dark:text-white">{{ $item->name }}</flux:text>
                                <flux:text class="text-xs">{{ $item->item_type->label() }}</flux:text>
                            </div>

                            <div>
                                <flux:select wire:model="conditions.{{ $item->id }}" :placeholder="__('Condition')" size="sm" :aria-label="__('Condition of :item', ['item' => $item->name])">
                                    @foreach (ItemCondition::cases() as $condition)
                                        <flux:select.option value="{{ $condition->value }}">{{ $condition->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="conditions.{{ $item->id }}" />
                            </div>

                            <div>
                                <flux:input wire:model="remarks.{{ $item->id }}" size="sm" maxlength="255" :placeholder="__('Remarks (optional)')" :aria-label="__('Remarks on :item', ['item' => $item->name])" />
                                <flux:error name="remarks.{{ $item->id }}" />
                            </div>
                        </div>
                    @endforeach
                </div>

                <flux:textarea wire:model="checkNotes" :label="__('Notes (optional)')" rows="2" maxlength="1000" />

                <div class="flex justify-end gap-2">
                    <flux:button variant="filled" wire:click="cancelCheck">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Save check') }}</flux:button>
                </div>
            </form>
        @endif

        <div class="space-y-2">
            @forelse ($this->checks as $check)
                @php($blocking = $check->blockingItemCount())
                <details wire:key="check-{{ $check->id }}" class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-2 px-4 py-3">
                        <flux:badge size="sm" :color="$check->kind === ConditionCheckKind::MoveIn ? 'sky' : 'zinc'">{{ $check->kind->label() }}</flux:badge>
                        <span class="text-sm font-medium">{{ $check->checked_at->format('M j, Y g:i A') }}</span>
                        @if ($check->checker)
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('by :name', ['name' => $check->checker->name]) }}</span>
                        @endif

                        @if ($blocking > 0)
                            <flux:badge size="sm" color="red">{{ trans_choice(':count not working or missing|:count not working or missing', $blocking) }}</flux:badge>
                        @endif

                        @if ($check->kind === ConditionCheckKind::MoveIn)
                            @if ($check->lease)
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">&middot; {{ __('for :tenant', ['tenant' => $check->lease->tenant->name]) }}</span>
                                <flux:badge size="sm" :color="$check->tenant_acknowledged_at ? 'green' : 'amber'">
                                    {{ $check->tenant_acknowledged_at ? __('Acknowledged :date', ['date' => $check->tenant_acknowledged_at->format('M j')]) : __('Not acknowledged yet') }}
                                </flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Waiting for a tenant') }}</flux:badge>
                            @endif
                        @endif
                    </summary>

                    <div class="space-y-3 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <ul class="space-y-1 text-sm">
                            @foreach ($check->items as $checkedItem)
                                <li class="flex flex-wrap items-center gap-2">
                                    <span>{{ $checkedItem->unitItem->name }}</span>
                                    <flux:badge size="sm" :color="$checkedItem->condition->color()">{{ $checkedItem->condition->label() }}</flux:badge>
                                    @if ($checkedItem->remarks)
                                        <span class="text-zinc-500 dark:text-zinc-400">&mdash; {{ $checkedItem->remarks }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        @if ($check->notes)
                            <flux:text>{{ $check->notes }}</flux:text>
                        @endif
                    </div>
                </details>
            @empty
                <flux:text class="text-zinc-400">{{ __('No checks recorded yet.') }}</flux:text>
            @endforelse
        </div>
    </div>
</section>
