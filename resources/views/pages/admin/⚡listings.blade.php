<?php

use App\Enums\ListingStatus;
use App\Enums\TeamRole;
use App\Models\UnitListing;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Listing review')] class extends Component
{
    /**
     * Listings waiting for review, oldest first, with their photos and only
     * the owner's membership of their team.
     *
     * @return Collection<int, UnitListing>
     */
    #[Computed]
    public function queue(): Collection
    {
        return UnitListing::where('status', ListingStatus::PendingReview)
            ->with([
                'photos',
                'unit.property.team.memberships' => fn ($memberships) => $memberships
                    ->where('role', TeamRole::Owner->value)
                    ->with('user'),
            ])
            ->orderBy('submitted_at')
            ->get();
    }

    /**
     * The landlord behind a listing: the owner of its team, by their own
     * name rather than the team's, which can be anything they typed.
     */
    public function landlordName(UnitListing $listing): string
    {
        $team = $listing->unit->property->team;

        return $team->memberships->first()?->user?->name ?? $team->name;
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Listing review') }}</flux:heading>
        <flux:subheading>{{ __('Listings waiting for approval, oldest first.') }}</flux:subheading>
    </div>

    @if ($this->queue->isEmpty())
        <div class="rounded-xl border border-zinc-200 p-8 text-center dark:border-zinc-700">
            <flux:heading>{{ __('Nothing to review') }}</flux:heading>
            <flux:subheading>{{ __('Submitted listings appear here.') }}</flux:subheading>
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($this->queue as $listing)
                @php($property = $listing->unit->property)
                <a
                    wire:key="queue-{{ $listing->id }}"
                    href="{{ route('admin.listings.show', ['listing' => $listing->id]) }}"
                    wire:navigate
                    class="group flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition hover:border-brand-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                >
                    <div class="relative">
                        @if ($cover = $listing->photos->first())
                            <img src="{{ $cover->url() }}" alt="{{ __('Cover photo of :title', ['title' => $listing->title]) }}" loading="lazy" decoding="async" class="aspect-[4/3] w-full object-cover">
                        @else
                            <x-unit-photo :unit="$listing->unit" />
                        @endif

                        {{-- Flux badges are see-through, so each sits on a white chip to stay readable over any photo. --}}
                        <span class="absolute end-2 top-2 inline-flex rounded-md bg-white shadow-sm">
                            <flux:badge :color="$listing->photos->isEmpty() ? 'amber' : 'zinc'" size="sm">
                                {{ trans_choice(':count photo|:count photos', $listing->photos->count()) }}
                            </flux:badge>
                        </span>
                    </div>

                    <div class="flex flex-col gap-0.5 p-3 text-center">
                        <span class="font-medium text-zinc-900 group-hover:text-brand-600">{{ $listing->title }}</span>
                        <span class="text-xs text-zinc-600">
                            {{ $property->name }} &middot; {{ __('Unit :number', ['number' => $listing->unit->unit_number]) }}
                        </span>
                        <span class="text-xs text-zinc-500">
                            {{ $property->type->label() }} &middot; &#8369;{{ number_format((float) $listing->unit->price) }}/mo
                        </span>
                        <span class="text-xs text-zinc-500">{{ $property->city }}, {{ $property->province }}</span>
                        <span class="text-xs text-zinc-500">{{ __('Landlord: :name', ['name' => $this->landlordName($listing)]) }}</span>
                        <span class="text-xs text-zinc-500">
                            {{ __('Submitted :time', ['time' => $listing->submitted_at?->diffForHumans()]) }}
                        </span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
