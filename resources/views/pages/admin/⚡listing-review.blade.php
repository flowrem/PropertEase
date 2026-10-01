<?php

use App\Enums\ListingStatus;
use App\Enums\TeamRole;
use App\Models\Amenity;
use App\Models\PaymentChannel;
use App\Models\Unit;
use App\Models\UnitListing;
use App\Models\User;
use App\Notifications\ListingReviewed;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Review listing')] class extends Component
{
    public int $listingId;

    public string $rejection_reason = '';

    public function mount(int $listing): void
    {
        $this->listingId = $listing;

        $this->listing;
    }

    #[Computed]
    public function listing(): UnitListing
    {
        return UnitListing::with(['unit.property.team', 'unit.amenities', 'photos'])->findOrFail($this->listingId);
    }

    /**
     * The person behind the listing: the owner of its team.
     */
    #[Computed]
    public function landlord(): ?User
    {
        return $this->listing->team()->owner();
    }

    /**
     * The landlord's active payment channels, so an obviously wrong or
     * mismatched QR can be caught during review.
     *
     * @return Collection<int, PaymentChannel>
     */
    #[Computed]
    public function paymentChannels(): Collection
    {
        return $this->listing->team()->paymentChannels()->active()->get();
    }

    public function approve(): void
    {
        $this->ensureSuperAdmin();

        $listing = $this->reviewableListing();

        if (! $listing) {
            return;
        }

        $listing->status = ListingStatus::Approved;
        $listing->rejection_reason = null;

        $this->finishReview($listing, __('Listing approved.'));
    }

    public function reject(): void
    {
        $this->ensureSuperAdmin();

        $listing = $this->reviewableListing();

        if (! $listing) {
            return;
        }

        $validated = $this->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ], [
            'rejection_reason.required' => __('Tell the landlord why so they can fix it.'),
        ]);

        $listing->status = ListingStatus::Rejected;
        $listing->rejection_reason = $validated['rejection_reason'];

        $this->finishReview($listing, __('Listing rejected.'));
    }

    /**
     * Livewire update requests do not reliably re-run route middleware, so
     * every action re-checks the role itself.
     */
    protected function ensureSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    protected function reviewableListing(): ?UnitListing
    {
        $listing = UnitListing::with('unit.property.team')->findOrFail($this->listingId);

        return $listing->status === ListingStatus::PendingReview ? $listing : null;
    }

    protected function finishReview(UnitListing $listing, string $toast): void
    {
        $listing->reviewed_at = now();
        $listing->reviewed_by = Auth::id();
        $listing->save();

        $listing->team()->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->get()
            ->each(fn ($member) => $member->notify(new ListingReviewed($listing)));

        Flux::toast(variant: 'success', text: $toast);

        $this->redirectRoute('admin.listings', navigate: true);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    @php($listing = $this->listing)
    @php($unit = $listing->unit)
    @php($property = $unit->property)
    @php($isPending = $listing->status === ListingStatus::PendingReview)

    <div>
        <flux:link :href="route('admin.listings')" wire:navigate class="text-sm">&larr; {{ __('All listings to review') }}</flux:link>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1">{{ $listing->title }}</flux:heading>
            <flux:badge :color="$listing->status->color()">{{ $listing->status->label() }}</flux:badge>
        </div>
        <flux:subheading>
            {{ $property->name }} &middot; {{ $property->type->label() }} &middot; {{ __('Unit :number', ['number' => $unit->unit_number]) }}
            @if ($listing->submitted_at)
                &middot; {{ __('Submitted :time', ['time' => $listing->submitted_at->diffForHumans()]) }}
            @endif
        </flux:subheading>
    </div>

    @if ($listing->photos->isNotEmpty())
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($listing->photos as $photo)
                <a href="{{ $photo->url() }}" target="_blank" rel="noopener noreferrer" @class(['sm:col-span-2 sm:row-span-2' => $loop->first])>
                    <img
                        src="{{ $photo->url() }}"
                        alt="{{ $listing->title }} ({{ $loop->iteration }})"
                        @if (! $loop->first) loading="lazy" @endif
                        width="800"
                        height="600"
                        class="aspect-[4/3] h-full w-full rounded-xl object-cover"
                    >
                </a>
            @endforeach
        </div>
    @else
        <flux:callout variant="warning" icon="photo" :heading="__('This listing has no photos.')" />
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="flex flex-col gap-6 lg:col-span-2">
            <div class="rounded-xl border border-zinc-200 bg-white p-5">
                <flux:heading>{{ __('Description') }}</flux:heading>
                <flux:text class="mt-2 whitespace-pre-line">{{ $listing->description }}</flux:text>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5">
                <flux:heading>{{ __('Unit details') }}</flux:heading>
                <dl class="mt-3 grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-zinc-500">{{ __('Monthly rent') }}</dt>
                        <dd class="text-zinc-900">&#8369;{{ number_format((float) $unit->price, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Downpayment requested') }}</dt>
                        <dd class="text-zinc-900">{{ $listing->downpayment_amount !== null ? '₱'.number_format((float) $listing->downpayment_amount, 2) : __('Not set') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Occupancy') }}</dt>
                        <dd class="text-zinc-900">
                            {{ $unit->allows_multiple_tenants
                                ? __('Shared, up to :count tenants', ['count' => $unit->capacity()])
                                : __('One tenant') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Floor area') }}</dt>
                        <dd class="text-zinc-900">{{ $unit->floor_area_sqm !== null ? Unit::formatFloorArea((float) $unit->floor_area_sqm).' m²' : __('Not set') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Bedrooms') }}</dt>
                        <dd class="text-zinc-900">{{ $unit->bedrooms > 0 ? $unit->bedrooms : __('Studio or bedspace') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Bathrooms') }}</dt>
                        <dd class="text-zinc-900">{{ $unit->bathrooms > 0 ? $unit->bathrooms : __('Shared bathroom') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Floor') }}</dt>
                        <dd class="text-zinc-900">{{ $unit->floor_level ?: __('Not set') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Unit status') }}</dt>
                        <dd class="text-zinc-900">{{ $unit->status->label() }}</dd>
                    </div>
                </dl>
            </div>

            @if ($unit->amenities->isNotEmpty())
                <div class="rounded-xl border border-zinc-200 bg-white p-5">
                    <flux:heading>{{ __("What's included") }}</flux:heading>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        @foreach (Amenity::groupByCategory($unit->amenities) as $category => $amenities)
                            <div>
                                <flux:text class="text-xs font-medium uppercase tracking-wide">{{ $category }}</flux:text>
                                <ul class="mt-1 space-y-1 text-sm text-zinc-900">
                                    @foreach ($amenities as $amenity)
                                        <li>
                                            {{ $amenity->name }}
                                            @if ($amenity->pivot->quantity > 1)
                                                <span class="text-zinc-500">&times; {{ $amenity->pivot->quantity }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rounded-xl border border-zinc-200 bg-white p-5">
                <flux:heading>{{ __('Location') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-900">
                    {{ $property->address_line }}, {{ $property->city }}, {{ $property->province }} {{ $property->postal_code }}
                </flux:text>
                @if ($property->map_url)
                    <flux:link :href="$property->map_url" external class="mt-2 inline-block text-sm">{{ __('Open in maps') }}</flux:link>
                @endif
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-5">
                <flux:heading>{{ __('Landlord') }}</flux:heading>
                <dl class="mt-3 flex flex-col gap-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">{{ __('Account owner') }}</dt>
                        <dd class="text-zinc-900">{{ $this->landlord?->name ?? $property->team->name }}</dd>
                        @if ($this->landlord)
                            <dd class="break-all text-zinc-500">{{ $this->landlord->email }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Listing contact') }}</dt>
                        <dd class="text-zinc-900">{{ $listing->contact_name }}</dd>
                        <dd><flux:link href="tel:{{ $listing->contact_phone }}">{{ $listing->contact_phone }}</flux:link></dd>
                        <dd><flux:link href="mailto:{{ $listing->contact_email }}" class="break-all">{{ $listing->contact_email }}</flux:link></dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5">
                <flux:heading>{{ __('Payment channels') }}</flux:heading>
                <div class="mt-3 flex flex-col gap-3">
                    @forelse ($this->paymentChannels as $channel)
                        <div wire:key="channel-{{ $channel->id }}" class="flex gap-4 rounded-lg border border-zinc-200 p-3">
                            @if ($channel->qrUrl())
                                <a href="{{ $channel->qrUrl() }}" target="_blank" rel="noopener noreferrer" class="shrink-0">
                                    <img src="{{ $channel->qrUrl() }}" alt="{{ __('QR code for :name', ['name' => $channel->account_name]) }}" loading="lazy" width="96" height="96" class="size-24 rounded bg-white object-contain">
                                </a>
                            @endif
                            <div class="min-w-0">
                                <flux:text class="font-medium text-zinc-900">{{ $channel->method->label() }}</flux:text>
                                <flux:text>{{ $channel->account_name }}</flux:text>
                                @if ($channel->bank_name)
                                    <flux:text>{{ $channel->bank_name }}</flux:text>
                                @endif
                                @if ($channel->account_number)
                                    <flux:text>{{ $channel->account_number }}</flux:text>
                                @endif
                            </div>
                        </div>
                    @empty
                        <flux:text class="text-amber-700">{{ __('This landlord has no active payment channel.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5">
                <flux:heading>{{ __('Decision') }}</flux:heading>

                @if ($isPending)
                    <div class="mt-3 flex flex-col gap-4">
                        <flux:button variant="primary" icon="check" wire:click="approve" class="w-full">{{ __('Approve listing') }}</flux:button>

                        <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4">
                            <flux:textarea wire:model="rejection_reason" :label="__('Reason for rejecting')" :description="__('The landlord sees this so they can fix the listing.')" rows="3" />
                            <flux:button variant="danger" icon="x-mark" wire:click="reject" class="w-full">{{ __('Reject listing') }}</flux:button>
                        </div>
                    </div>
                @else
                    <flux:text class="mt-2">{{ __('This listing is no longer waiting for review.') }}</flux:text>
                    @if ($listing->rejection_reason)
                        <flux:text class="mt-2 text-zinc-900">{{ __('Reason given: :reason', ['reason' => $listing->rejection_reason]) }}</flux:text>
                    @endif
                @endif
            </div>
        </div>
    </div>
</section>
