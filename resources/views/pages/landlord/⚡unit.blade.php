<?php

use App\Actions\Units\StoreUnitPhoto;
use App\Enums\ConcernStatus;
use App\Models\Team;
use App\Models\Unit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Unit')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $unitId;

    public $photo = null;

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
            ->with(['property', 'listing', 'amenities', 'activeLeases.tenant', 'activeLeases.currentInvoice'])
            ->withBedSpaces()
            ->withCount(['heldReservations', 'concerns as open_concerns_count' => fn ($concerns) => $concerns
                ->where('concerns.status', '!=', ConcernStatus::Resolved->value)])
            ->findOrFail($this->unitId);
    }

    /**
     * Save the photo as soon as it is picked.
     */
    public function updatedPhoto(StoreUnitPhoto $storeUnitPhoto): void
    {
        $this->validate(
            ['photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']],
            ['photo.max' => __('The photo must be 5 MB or smaller.')],
        );

        $storeUnitPhoto->handle($this->unit, $this->photo);

        $this->reset('photo');
        unset($this->unit);

        Flux::toast(variant: 'success', text: __('Photo saved.'));
    }

    public function removePhoto(StoreUnitPhoto $storeUnitPhoto): void
    {
        $storeUnitPhoto->remove($this->unit);

        unset($this->unit);

        Flux::toast(variant: 'success', text: __('Photo removed.'));
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    @php($unit = $this->unit)

    <x-unit-header :unit="$unit" current="overview" />

    @unless ($unit->hasLockedDetails())
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.heading>{{ __('Add this unit\'s floor area and rooms') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Its listing cannot be submitted until they are filled in.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-3">
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <x-unit-photo :unit="$unit" />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 hover:bg-zinc-50">
                    <flux:icon.camera class="size-4" />
                    <span wire:loading.remove wire:target="photo">{{ $unit->photo_path ? __('Change photo') : __('Add a photo') }}</span>
                    <span wire:loading wire:target="photo">{{ __('Uploading...') }}</span>
                    <input type="file" accept="image/jpeg,image/png,image/webp" wire:model="photo" class="sr-only">
                </label>

                @if ($unit->photo_path)
                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="removePhoto">{{ __('Remove photo') }}</flux:button>
                @endif
            </div>
            <flux:text class="text-xs text-zinc-500">{{ __('Optional. JPG, PNG or WebP, up to 5 MB. It is shrunk before saving so the Properties page loads fast.') }}</flux:text>
            <flux:error name="photo" />
        </div>

        <div class="flex flex-col gap-4">
            <dl class="grid grid-cols-2 gap-4 rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                <div>
                    <dt class="text-zinc-500">{{ __('Floor') }}</dt>
                    <dd class="font-medium">{{ $unit->floor_level ?: __('Not set') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Floor area') }}</dt>
                    <dd class="font-medium">{{ $unit->hasLockedDetails() ? Unit::formatFloorArea((float) $unit->floor_area_sqm).' m²' : __('Not set') }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Rooms') }}</dt>
                    <dd class="font-medium">
                        {{ $unit->bedrooms === 0 ? __('Studio') : $unit->bedrooms.' '.Str::plural('bedroom', $unit->bedrooms) }}
                        &middot; {{ $unit->bathrooms === 0 ? __('Shared bathroom') : $unit->bathrooms.' '.Str::plural('bathroom', $unit->bathrooms) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Monthly rent') }}</dt>
                    <dd class="font-medium">&#8369;{{ number_format((float) $unit->price, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Tenants') }}</dt>
                    <dd class="font-medium">
                        {{ $unit->allows_multiple_tenants ? __(':taken of :capacity', ['taken' => $unit->takenSlotCount(), 'capacity' => $unit->capacity()]) : ($unit->activeLeases->isNotEmpty() ? __('1 of 1') : __('0 of 1')) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-zinc-500">{{ __('Open reports') }}</dt>
                    <dd class="font-medium">
                        @if ($unit->open_concerns_count > 0)
                            <flux:link :href="route('landlord.maintenance')" wire:navigate>{{ $unit->open_concerns_count }}</flux:link>
                        @else
                            {{ __('None') }}
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm" class="mb-2">{{ __('Who lives here') }}</flux:heading>
                @forelse ($unit->activeLeases as $lease)
                    <div wire:key="lease-{{ $lease->id }}" class="flex items-center justify-between gap-3 py-1.5 text-sm">
                        <span>
                            {{ $lease->tenant->name }}
                            <span class="text-zinc-500">&middot; {{ __('since :date', ['date' => $lease->start_date->format('M j, Y')]) }}</span>
                        </span>
                        @if ($lease->currentInvoice)
                            <flux:badge :color="$lease->currentInvoice->status->color()" size="sm">
                                {{ $lease->currentInvoice->status->label() }} &middot; {{ __('due') }} {{ $lease->currentInvoice->due_date->format('M j') }}
                            </flux:badge>
                        @endif
                    </div>
                @empty
                    <flux:text class="text-zinc-500">
                        {{ __('Nobody yet.') }}
                        <flux:link :href="route('tenants')" wire:navigate>{{ __('Assign a tenant') }}</flux:link>
                    </flux:text>
                @endforelse
            </div>

            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm" class="mb-2">{{ __('What\'s included') }}</flux:heading>
                @if ($unit->amenities->isEmpty())
                    <flux:text class="text-zinc-500">{{ __('No amenities ticked yet.') }}</flux:text>
                @else
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($unit->amenities as $amenity)
                            <li wire:key="amenity-{{ $amenity->id }}">
                                <flux:badge size="sm" color="zinc">
                                    {{ $amenity->pivot->quantity > 1 ? $amenity->pivot->quantity.' × ' : '' }}{{ $amenity->name }}
                                </flux:badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <flux:heading size="sm">{{ __('Public listing') }}</flux:heading>
                    @if ($unit->listing)
                        <flux:badge :color="$unit->listing->status->color()" size="sm" class="mt-1">{{ $unit->listing->status->label() }}</flux:badge>
                    @else
                        <flux:text class="text-zinc-500">{{ __('Not listed on Find a place.') }}</flux:text>
                    @endif
                </div>
                <flux:button size="sm" :href="route('listings')" wire:navigate>{{ $unit->listing ? __('Open listings') : __('Create a listing') }}</flux:button>
            </div>
        </div>
    </div>
</section>
