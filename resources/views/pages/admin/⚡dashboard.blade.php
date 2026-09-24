<?php

use App\Enums\LeaseStatus;
use App\Enums\ListingStatus;
use App\Enums\ReservationStatus;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin dashboard')] class extends Component
{
    private const WEEKS = 12;

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
     * Everything waiting on a Super Admin decision.
     *
     * @return array{landlords: int, listings: int, total: int}
     */
    #[Computed]
    public function needsReview(): array
    {
        $landlords = $this->landlords['awaiting'];
        $listings = $this->listingCounts[ListingStatus::PendingReview->value] ?? 0;

        return ['landlords' => $landlords, 'listings' => $listings, 'total' => $landlords + $listings];
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

    /**
     * Units by state across the platform.
     *
     * @return array{total: int, occupied: int, vacant: int, maintenance: int}
     */
    #[Computed]
    public function occupancy(): array
    {
        $counts = Unit::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'occupied' => (int) ($counts[UnitStatus::Occupied->value] ?? 0),
            'vacant' => (int) ($counts[UnitStatus::Vacant->value] ?? 0),
            'maintenance' => (int) ($counts[UnitStatus::UnderMaintenance->value] ?? 0),
        ];
    }

    /**
     * @return array<int, array{label: string, hint: string, value: int}>
     */
    #[Computed]
    public function newLandlordsPerWeek(): array
    {
        return $this->perWeek(Team::query());
    }

    /**
     * @return array<int, array{label: string, hint: string, value: int}>
     */
    #[Computed]
    public function reservationsPerWeek(): array
    {
        return $this->perWeek(Reservation::query());
    }

    /**
     * Rows created in each of the last weeks, oldest first, empty weeks included.
     *
     * @param  Builder<Team>|Builder<Reservation>  $query
     * @return array<int, array{label: string, hint: string, value: int}>
     */
    protected function perWeek(Builder $query): array
    {
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks(self::WEEKS - 1);

        $counts = $query
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->countBy(fn ($row) => $row->created_at->startOfWeek()->toDateString());

        return collect(range(0, self::WEEKS - 1))
            ->map(function (int $offset) use ($start, $counts) {
                $week = $start->addWeeks($offset);

                return [
                    'label' => $week->format('M j'),
                    'hint' => $week->format('M j').' to '.$week->endOfWeek()->format('M j'),
                    'value' => (int) ($counts[$week->toDateString()] ?? 0),
                ];
            })
            ->all();
    }
}; ?>

<div class="flex w-full flex-col gap-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>
        <flux:subheading>{{ __('Platform-wide numbers across every landlord team.') }}</flux:subheading>
    </div>

    {{-- Tier 1: the one thing that needs a decision --}}
    <section class="rounded-lg border border-zinc-200 p-6 dark:border-zinc-700" aria-labelledby="needs-review">
        <flux:text id="needs-review" class="text-zinc-500 dark:text-zinc-400">{{ __('Waiting for your review') }}</flux:text>

        <div class="mt-2 flex flex-wrap items-end gap-x-8 gap-y-3">
            <div class="text-5xl font-semibold leading-none">{{ number_format($this->needsReview['total']) }}</div>

            @if ($this->needsReview['total'] === 0)
                <div class="flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                    <flux:icon name="check-circle" variant="mini" class="size-5" />
                    <span>{{ __('Nothing is waiting on you.') }}</span>
                </div>
            @else
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <a href="{{ route('admin.landlords') }}" wire:navigate class="hover:underline">
                        <span class="font-semibold tabular-nums">{{ number_format($this->needsReview['landlords']) }}</span>
                        {{ trans_choice('landlord ID|landlord IDs', $this->needsReview['landlords']) }}
                    </a>
                    <a href="{{ route('admin.listings') }}" wire:navigate class="hover:underline">
                        <span class="font-semibold tabular-nums">{{ number_format($this->needsReview['listings']) }}</span>
                        {{ trans_choice('listing|listings', $this->needsReview['listings']) }}
                    </a>
                </div>
            @endif
        </div>
    </section>

    {{-- Tier 2: headline numbers --}}
    <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ([
            __('Landlords') => $this->landlords['total'],
            __('Properties') => $this->platform['properties'],
            __('Units') => $this->platform['units'],
            __('Active leases') => $this->platform['activeLeases'],
        ] as $label => $count)
            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($count) }}</div>
            </div>
        @endforeach
    </section>

    {{-- Tier 3: trends --}}
    <section class="grid gap-4 lg:grid-cols-2">
        <x-admin.column-chart
            :title="__('New landlord teams per week')"
            :description="__('Last 12 weeks')"
            :unit="__('teams')"
            :points="$this->newLandlordsPerWeek"
        />
        <x-admin.column-chart
            :title="__('Reservations submitted per week')"
            :description="__('Last 12 weeks, all landlords')"
            :unit="__('reservations')"
            :points="$this->reservationsPerWeek"
        />
    </section>

    {{-- Tier 4: where things stand --}}
    <section class="grid gap-4 lg:grid-cols-2">
        <x-admin.stacked-bar
            :title="__('Landlord review')"
            :description="__('Every landlord team by review state')"
            :segments="[
                ['label' => __('Approved'), 'value' => $this->landlords['approved'], 'color' => 'bg-[#0ca30c]', 'icon' => 'check-circle'],
                ['label' => __('Awaiting review'), 'value' => $this->landlords['awaiting'], 'color' => 'bg-[#fab219]', 'icon' => 'clock'],
                ['label' => __('Rejected'), 'value' => $this->landlords['rejected'], 'color' => 'bg-[#d03b3b]', 'icon' => 'x-circle'],
            ]"
        />

        <x-admin.bar-list
            :title="__('Listings by status')"
            :description="__('Pending review is highlighted')"
            :rows="collect(ListingStatus::cases())->map(fn (ListingStatus $status) => [
                'label' => $status->label(),
                'value' => $this->listingCounts[$status->value] ?? 0,
                'emphasis' => $status === ListingStatus::PendingReview,
            ])->all()"
        />
    </section>

    {{-- Tier 5: capacity and reservations in flight --}}
    <section class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Unit occupancy') }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Occupied units out of every unit on the platform') }}</flux:text>

            @if ($this->occupancy['total'] === 0)
                <flux:text class="mt-5 text-zinc-500 dark:text-zinc-400">{{ __('No units yet.') }}</flux:text>
            @else
                @php($occupiedShare = round($this->occupancy['occupied'] / $this->occupancy['total'] * 100))

                <div class="mt-5 flex items-baseline gap-2">
                    <span class="text-4xl font-semibold leading-none">{{ $occupiedShare }}%</span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __(':occupied of :total units occupied', ['occupied' => number_format($this->occupancy['occupied']), 'total' => number_format($this->occupancy['total'])]) }}
                    </span>
                </div>

                <div
                    class="mt-3 h-3 overflow-hidden rounded-full bg-brand-500/20"
                    role="meter"
                    aria-valuemin="0"
                    aria-valuemax="{{ $this->occupancy['total'] }}"
                    aria-valuenow="{{ $this->occupancy['occupied'] }}"
                    aria-label="{{ __('Occupied units') }}"
                >
                    <div class="h-full rounded-full bg-brand-500" style="width: {{ $occupiedShare }}%"></div>
                </div>

                <dl class="mt-4 flex gap-6 text-sm">
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Vacant') }}</dt>
                        <dd class="font-medium tabular-nums">{{ number_format($this->occupancy['vacant']) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Under maintenance') }}</dt>
                        <dd class="font-medium tabular-nums">{{ number_format($this->occupancy['maintenance']) }}</dd>
                    </div>
                </dl>
            @endif
        </div>

        <x-admin.bar-list
            :title="__('Reservations in flight')"
            :description="__('Counts only, across all landlords')"
            :rows="[
                ['label' => __('Pending review'), 'value' => $this->platform['pendingReservations'], 'emphasis' => true],
                ['label' => __('Holding a slot'), 'value' => $this->platform['heldReservations']],
            ]"
        />
    </section>
</div>
