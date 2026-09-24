<?php

use App\Enums\LeaseStatus;
use App\Enums\ListingStatus;
use App\Enums\ReservationStatus;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin dashboard')] class extends Component
{
    /**
     * Landlord teams by review state.
     *
     * @return array{total: int, awaiting: int, approved: int, rejected: int}
     */
    #[Computed]
    public function landlords(): array
    {
        return [
            'total' => Team::count(),
            'awaiting' => Team::whereNull('approved_at')->whereNull('rejected_at')->count(),
            'approved' => Team::whereNotNull('approved_at')->count(),
            'rejected' => Team::whereNull('approved_at')->whereNotNull('rejected_at')->count(),
        ];
    }

    /**
     * Listing counts keyed by status value.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function listingCounts(): array
    {
        return UnitListing::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Platform-wide totals. Counts only, never tenant or reservation details.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function platform(): array
    {
        return [
            'properties' => Property::count(),
            'units' => Unit::count(),
            'activeLeases' => Lease::where('status', LeaseStatus::Active->value)->count(),
            'pendingReservations' => Reservation::where('status', ReservationStatus::Pending->value)->count(),
            'heldReservations' => Reservation::where('status', ReservationStatus::Approved->value)->count(),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
        <flux:subheading>{{ __('Platform-wide numbers across every landlord team.') }}</flux:subheading>
    </div>

    <section class="space-y-3">
        <flux:heading size="sm">{{ __('Landlords') }}</flux:heading>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                __('Total') => $this->landlords['total'],
                __('Awaiting review') => $this->landlords['awaiting'],
                __('Approved') => $this->landlords['approved'],
                __('Rejected') => $this->landlords['rejected'],
            ] as $label => $count)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold">{{ number_format($count) }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="space-y-3">
        <flux:heading size="sm">{{ __('Listings') }}</flux:heading>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            @foreach (ListingStatus::cases() as $status)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $status->label() }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold">{{ number_format($this->listingCounts[$status->value] ?? 0) }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="space-y-3">
        <flux:heading size="sm">{{ __('Platform') }}</flux:heading>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            @foreach ([
                __('Properties') => $this->platform['properties'],
                __('Units') => $this->platform['units'],
                __('Active leases') => $this->platform['activeLeases'],
                __('Reservations pending') => $this->platform['pendingReservations'],
                __('Reservations holding a slot') => $this->platform['heldReservations'],
            ] as $label => $count)
                <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
                    <div class="mt-1 text-2xl font-semibold">{{ number_format($count) }}</div>
                </div>
            @endforeach
        </div>
    </section>
</div>
