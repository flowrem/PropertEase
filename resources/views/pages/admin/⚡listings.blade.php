<?php

use App\Enums\ListingStatus;
use App\Enums\TeamRole;
use App\Models\PaymentChannel;
use App\Models\UnitListing;
use App\Notifications\ListingReviewed;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Listing review')] class extends Component
{
    public ?int $selectedListingId = null;

    public string $rejection_reason = '';

    /**
     * Listings waiting for review, oldest first.
     *
     * @return Collection<int, UnitListing>
     */
    #[Computed]
    public function queue(): Collection
    {
        return UnitListing::where('status', ListingStatus::PendingReview)
            ->with('unit.property')
            ->orderBy('submitted_at')
            ->get();
    }

    #[Computed]
    public function selectedListing(): ?UnitListing
    {
        return $this->selectedListingId
            ? UnitListing::with(['unit.property.team', 'photos'])->findOrFail($this->selectedListingId)
            : null;
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
        return $this->selectedListing
            ? $this->selectedListing->team()->paymentChannels()->active()->get()
            : collect();
    }

    /**
     * Open a listing's review, or close it when it's already open. Only one
     * is open at a time, since they share the rejection reason field.
     */
    public function toggle(int $listingId): void
    {
        $this->ensureSuperAdmin();

        $this->selectedListingId = $this->selectedListingId === $listingId ? null : $listingId;
        $this->rejection_reason = '';
        $this->resetValidation();
        unset($this->selectedListing, $this->paymentChannels);
    }

    public function approve(int $listingId): void
    {
        $this->ensureSuperAdmin();

        $listing = $this->reviewableListing($listingId);

        if (! $listing) {
            return;
        }

        $listing->status = ListingStatus::Approved;
        $listing->rejection_reason = null;

        $this->finishReview($listing, __('Listing approved.'));
    }

    public function reject(int $listingId): void
    {
        $this->ensureSuperAdmin();

        $listing = $this->reviewableListing($listingId);

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

    protected function reviewableListing(int $listingId): ?UnitListing
    {
        $listing = UnitListing::with('unit.property.team')->findOrFail($listingId);

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

        $this->selectedListingId = null;
        $this->rejection_reason = '';
        unset($this->queue, $this->selectedListing, $this->paymentChannels);

        Flux::toast(variant: 'success', text: $toast);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Listing review') }}</flux:heading>
        <flux:subheading>{{ __('Listings waiting for approval, oldest first.') }}</flux:subheading>
    </div>

    @forelse ($this->queue as $queuedListing)
        @php($isOpen = $selectedListingId === $queuedListing->id)
        <div wire:key="queue-{{ $queuedListing->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div
                wire:click="toggle({{ $queuedListing->id }})"
                class="flex w-full cursor-pointer items-center justify-between gap-4 p-4 text-left"
            >
                <div>
                    <flux:heading>{{ $queuedListing->title }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ $queuedListing->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $queuedListing->unit->unit_number]) }}
                    </flux:text>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <flux:badge color="zinc">
                        {{ __('Submitted :time', ['time' => $queuedListing->submitted_at?->diffForHumans()]) }}
                    </flux:badge>
                    @if ($isOpen)
                        <flux:icon.chevron-up class="size-4 text-zinc-500" />
                    @else
                        <flux:icon.chevron-down class="size-4 text-zinc-500" />
                    @endif
                </div>
            </div>

            @if ($isOpen && $this->selectedListing)
                @php($listing = $this->selectedListing)
                @php($property = $listing->unit->property)

                <div class="flex flex-col gap-5 border-t border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="text-zinc-500">
                        {{ $property->team->name }} &middot; {{ $property->name }} ({{ $property->type->label() }})
                    </flux:text>

                    @if ($listing->photos->isNotEmpty())
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach ($listing->photos as $photo)
                                <img src="{{ $photo->url() }}" alt="" loading="lazy" width="160" height="120" class="aspect-[4/3] w-full rounded object-cover">
                            @endforeach
                        </div>
                    @else
                        <flux:text class="text-amber-400">{{ __('This listing has no photos.') }}</flux:text>
                    @endif

                    <flux:text class="whitespace-pre-line">{{ $listing->description }}</flux:text>

                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-zinc-500">{{ __('Address') }}</dt>
                            <dd>{{ $property->address_line }}, {{ $property->city }}, {{ $property->province }} {{ $property->postal_code }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Monthly price') }}</dt>
                            <dd>&#8369;{{ number_format((float) $listing->unit->price, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Contact') }}</dt>
                            <dd>{{ $listing->contact_name }} &middot; {{ $listing->contact_phone }} &middot; {{ $listing->contact_email }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">{{ __('Downpayment requested') }}</dt>
                            <dd>{{ $listing->downpayment_amount !== null ? '₱'.number_format((float) $listing->downpayment_amount, 2) : __('Not set') }}</dd>
                        </div>
                    </dl>

                    <div class="flex flex-col gap-3">
                        <flux:heading size="sm">{{ __('Payment channels') }}</flux:heading>
                        @forelse ($this->paymentChannels as $channel)
                            <div wire:key="channel-{{ $channel->id }}" class="flex gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                @if ($channel->qrUrl())
                                    <img src="{{ $channel->qrUrl() }}" alt="{{ __('QR code for :name', ['name' => $channel->account_name]) }}" loading="lazy" width="96" height="96" class="size-24 shrink-0 rounded bg-white object-contain">
                                @endif
                                <div>
                                    <flux:text class="font-medium">{{ $channel->method->label() }}</flux:text>
                                    <flux:text class="text-zinc-500">{{ $channel->account_name }}</flux:text>
                                    @if ($channel->bank_name)
                                        <flux:text class="text-zinc-500">{{ $channel->bank_name }}</flux:text>
                                    @endif
                                    @if ($channel->account_number)
                                        <flux:text class="text-zinc-500">{{ $channel->account_number }}</flux:text>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <flux:text class="text-amber-400">{{ __('This landlord has no active payment channel.') }}</flux:text>
                        @endforelse
                    </div>

                    <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:textarea wire:model="rejection_reason" :label="__('Reason (required to reject)')" rows="3" />

                        <div class="flex justify-end gap-2">
                            <flux:button variant="danger" wire:click="reject({{ $listing->id }})">{{ __('Reject') }}</flux:button>
                            <flux:button variant="primary" wire:click="approve({{ $listing->id }})">{{ __('Approve') }}</flux:button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 p-8 text-center dark:border-zinc-700">
            <flux:heading>{{ __('Nothing to review') }}</flux:heading>
            <flux:subheading>{{ __('Submitted listings appear here.') }}</flux:subheading>
        </div>
    @endforelse
</div>
