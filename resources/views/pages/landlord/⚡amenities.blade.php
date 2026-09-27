<?php

use App\Enums\AmenityCategory;
use App\Models\Amenity;
use App\Models\Team;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Amenities')] class extends Component
{
    public string $name = '';

    public string $category = AmenityCategory::Furniture->value;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->canManageListingsOn($this->team);
    }

    /**
     * @return EloquentCollection<int, Amenity>
     */
    #[Computed]
    public function ownAmenities(): EloquentCollection
    {
        return $this->team->amenities()->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * The platform defaults every landlord gets, grouped by category label.
     *
     * @return Collection<string, EloquentCollection<int, Amenity>>
     */
    #[Computed]
    public function platformDefaults(): Collection
    {
        return Amenity::groupByCategory(
            Amenity::query()->whereNull('team_id')->active()->orderBy('name')->get(),
        );
    }

    public function addAmenity(): void
    {
        Gate::authorize('create', [Amenity::class, $this->team]);

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $taken = Amenity::availableTo($this->team)
                        ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
                        ->exists();

                    if ($taken) {
                        $fail(__('An amenity with this name is already on your list.'));
                    }
                },
            ],
            'category' => ['required', Rule::enum(AmenityCategory::class)],
        ]);

        $this->team->amenities()->create([
            'name' => trim($validated['name']),
            'category' => $validated['category'],
        ]);

        $this->reset('name');
        unset($this->ownAmenities);

        Flux::toast(variant: 'success', text: __('Amenity added.'));
    }

    public function toggleActive(int $amenityId): void
    {
        $amenity = $this->team->amenities()->findOrFail($amenityId);

        Gate::authorize('update', $amenity);

        $amenity->update(['is_active' => ! $amenity->is_active]);

        unset($this->ownAmenities);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Amenities') }}</flux:heading>
        <flux:subheading>{{ __('What your units can come with. Tick them on each unit from the Properties page.') }}</flux:subheading>
    </div>

    @if (! $this->canManage)
        <flux:text class="text-zinc-400">{{ __('You can view this list. Ask your landlord or a manager to change it.') }}</flux:text>
    @endif

    <div class="space-y-4">
        <div>
            <flux:heading size="lg" level="2">{{ __('Your own amenities') }}</flux:heading>
            <flux:text>{{ __('Add anything the list below is missing. A unit can have up to one of each per tenant it fits. A deactivated amenity stays on the units that have it but cannot be ticked on new ones.') }}</flux:text>
        </div>

        @if ($this->canManage)
            <form wire:submit="addAmenity" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Rooftop access')" maxlength="60" required />
                </div>

                <flux:select wire:model="category" :label="__('Category')" class="sm:max-w-48">
                    @foreach (AmenityCategory::cases() as $option)
                        <flux:select.option value="{{ $option->value }}">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button type="submit" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
            </form>
        @endif

        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->ownAmenities as $amenity)
                <div wire:key="amenity-{{ $amenity->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:text class="font-medium text-zinc-800 dark:text-white">{{ $amenity->name }}</flux:text>
                        <flux:badge size="sm" color="zinc">{{ $amenity->category->label() }}</flux:badge>
                        @unless ($amenity->is_active)
                            <flux:badge size="sm" color="amber">{{ __('Inactive') }}</flux:badge>
                        @endunless
                    </div>

                    @if ($this->canManage)
                        <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $amenity->id }})">
                            {{ $amenity->is_active ? __('Deactivate') : __('Activate') }}
                        </flux:button>
                    @endif
                </div>
            @empty
                <flux:text class="px-4 py-3 text-zinc-400">{{ __('You have not added any of your own yet.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <div class="space-y-4">
        <div>
            <flux:heading size="lg" level="2">{{ __('Included for every landlord') }}</flux:heading>
            <flux:text>{{ __('Each shows how many a unit can have. Beds show how many people one sleeps and the floor it covers; together they may cover at most half of a unit\'s sleeping area and sleep no more tenants than its floor area fits.') }}</flux:text>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->platformDefaults as $category => $amenities)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ $category }}</flux:heading>

                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($amenities as $amenity)
                            <li class="flex items-baseline justify-between gap-3">
                                <span>{{ $amenity->name }}</span>
                                <span class="text-end text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($amenity->isBed())
                                        {{ trans_choice('sleeps :count, covers :area m²|sleeps :count, covers :area m²', $amenity->sleeps, ['area' => Unit::formatFloorArea((float) $amenity->footprint_sqm)]) }}
                                    @elseif ($amenity->isSingle())
                                        {{ __('yes or no') }}
                                    @else
                                        {{ $amenity->quantity_basis->describe($amenity->quantity_per) }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</section>
