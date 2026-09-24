<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use App\Enums\LeaseStatus;
use App\Enums\UnitStatus;
use App\Models\Concern;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Team;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Home')] class extends Component
{
    public bool $showLeaveModal = false;

    public function mount(): void
    {
        if ($this->isLandlord && $this->team->properties()->doesntExist()) {
            $this->redirectRoute('setup', navigate: true);
        }
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function isLandlord(): bool
    {
        return Auth::user()->isLandlordOn($this->team);
    }

    #[Computed]
    public function firstName(): string
    {
        return Str::before(Auth::user()->name, ' ');
    }

    /**
     * The tenant's active lease on this team, with its unit eager loaded.
     */
    #[Computed]
    public function currentLease(): ?Lease
    {
        if ($this->isLandlord) {
            return null;
        }

        return Auth::user()->leases()
            ->where('status', LeaseStatus::Active->value)
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['unit.property', 'currentRent'])
            ->latest()
            ->first();
    }

    /**
     * Every outstanding invoice on this team's units, with what's needed to
     * work out balances and who owes them.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function outstandingInvoices(): Collection
    {
        return Invoice::outstanding()
            ->whereHas('lease.unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['payments', 'lease.tenant', 'lease.unit'])
            ->get();
    }

    /**
     * Headline money figures plus the tenants furthest behind.
     *
     * @return array{outstanding: float, pastDue: float, behind: Collection<int, array{name: string, unit: string, amount: float, since: CarbonImmutable}>}
     */
    #[Computed]
    public function money(): array
    {
        $pastDue = $this->outstandingInvoices->filter(fn (Invoice $invoice) => $invoice->isPastDue());

        $behind = $pastDue
            ->groupBy(fn (Invoice $invoice) => $invoice->lease->tenant_id)
            ->map(fn (Collection $invoices) => [
                'name' => $invoices->first()->lease->tenant->name,
                'unit' => $invoices->first()->lease->unit->unit_number,
                'amount' => round($invoices->sum(fn (Invoice $invoice) => $invoice->balanceDue()), 2),
                'since' => $invoices->min('due_date'),
            ])
            ->sortByDesc('amount')
            ->take(5)
            ->values();

        return [
            'outstanding' => round($this->outstandingInvoices->sum(fn (Invoice $invoice) => $invoice->balanceDue()), 2),
            'pastDue' => round($pastDue->sum(fn (Invoice $invoice) => $invoice->balanceDue()), 2),
            'behind' => $behind,
        ];
    }

    /**
     * Unresolved high and urgent maintenance requests, most important first.
     *
     * @return Collection<int, Concern>
     */
    #[Computed]
    public function urgentMaintenance(): Collection
    {
        return $this->teamConcerns(ConcernCategory::Maintenance)
            ->whereIn('priority', [ConcernPriority::High->value, ConcernPriority::Urgent->value])
            ->get()
            ->sort(fn (Concern $a, Concern $b) => [$b->priority->rank(), $a->created_at] <=> [$a->priority->rank(), $b->created_at])
            ->values();
    }

    /**
     * Complaints still waiting on a first response, oldest first.
     *
     * @return Collection<int, Concern>
     */
    #[Computed]
    public function unansweredComplaints(): Collection
    {
        return $this->teamConcerns(ConcernCategory::Complaint)
            ->where('status', ConcernStatus::Pending->value)
            ->doesntHave('updates')
            ->oldest()
            ->get();
    }

    /**
     * Occupancy figures across every unit on this team.
     *
     * @return array{occupied: int, vacant: int, maintenance: int, openSlots: int}
     */
    #[Computed]
    public function occupancy(): array
    {
        $units = Unit::whereHas('property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->withCount('activeLeases')
            ->get();

        return [
            'occupied' => $units->where('status', UnitStatus::Occupied)->count(),
            'vacant' => $units->where('status', UnitStatus::Vacant)->count(),
            'maintenance' => $units->where('status', UnitStatus::UnderMaintenance)->count(),
            'openSlots' => $units
                ->reject(fn (Unit $unit) => $unit->status === UnitStatus::UnderMaintenance)
                ->sum(fn (Unit $unit) => $unit->slotsAvailable()),
        ];
    }

    /**
     * Unresolved concerns of one category on this team, with their tenant and unit.
     *
     * @return Builder<Concern>
     */
    protected function teamConcerns(ConcernCategory $category): Builder
    {
        return Concern::where('category', $category->value)
            ->where('status', '!=', ConcernStatus::Resolved->value)
            ->whereHas('lease.unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['lease.tenant', 'lease.unit']);
    }

    public function confirmLeaveUnit(): void
    {
        abort_unless($this->currentLease, 404);

        $this->showLeaveModal = true;
    }

    public function leaveUnit(): void
    {
        $lease = $this->currentLease;

        abort_unless($lease, 404);

        $lease->end(LeaseStatus::Ended);

        $this->closeLeaveModal();
        unset($this->currentLease);

        Flux::toast(variant: 'success', text: __('You have left the unit.'));
    }

    public function closeLeaveModal(): void
    {
        $this->showLeaveModal = false;
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <livewire:pages::teams.pending-invitations-modal />

    <div>
        <flux:heading size="xl" level="1">
            {{ __('Welcome back, :name', ['name' => $this->firstName]) }}
        </flux:heading>
        <flux:subheading>
            @if ($this->isLandlord)
                {{ __('Where :team stands today, most important first.', ['team' => $this->team->name]) }}
            @else
                {{ __("Here's everything available to you in :app.", ['app' => config('app.name')]) }}
            @endif
        </flux:subheading>
    </div>

    @if ($this->isLandlord)
        <section class="flex flex-col gap-4" aria-labelledby="money-heading">
            <div class="flex items-baseline justify-between gap-4">
                <flux:heading id="money-heading" size="lg">{{ __('Money owed') }}</flux:heading>
                <flux:link :href="route('invoices')" wire:navigate>{{ __('Open invoices') }}</flux:link>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Past due') }}</flux:text>
                    <flux:heading size="xl" class="{{ $this->money['pastDue'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                        &#8369;{{ number_format($this->money['pastDue'], 2) }}
                    </flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Outstanding in total') }}</flux:text>
                    <flux:heading size="xl">&#8369;{{ number_format($this->money['outstanding'], 2) }}</flux:heading>
                </div>
            </div>

            @if ($this->money['behind']->isNotEmpty())
                <ul class="grid gap-2" data-test="tenants-behind">
                    @foreach ($this->money['behind'] as $row)
                        <li wire:key="behind-{{ $loop->index }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                            <div>
                                <flux:heading size="sm">{{ $row['name'] }}</flux:heading>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">
                                    {{ __('Unit :number', ['number' => $row['unit']]) }}
                                    &middot; {{ __('overdue since :date', ['date' => $row['since']->format('M j')]) }}
                                </flux:text>
                            </div>
                            <flux:badge color="red">&#8369;{{ number_format($row['amount'], 2) }}</flux:badge>
                        </li>
                    @endforeach
                </ul>
            @else
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No tenant is past due right now.') }}</flux:text>
            @endif
        </section>

        <section class="flex flex-col gap-4" aria-labelledby="attention-heading">
            <flux:heading id="attention-heading" size="lg">{{ __('Needs your attention') }}</flux:heading>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" data-test="urgent-maintenance">
                    <div class="flex items-baseline justify-between gap-4">
                        <flux:heading>{{ __('Urgent and high maintenance') }}</flux:heading>
                        <flux:link :href="route('landlord.maintenance')" wire:navigate>{{ __('See all') }}</flux:link>
                    </div>

                    @forelse ($this->urgentMaintenance->take(5) as $concern)
                        <div wire:key="maintenance-{{ $concern->id }}" class="flex items-start justify-between gap-3">
                            <div>
                                <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">{{ $concern->title }}</flux:text>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">
                                    {{ $concern->lease->tenant->name }} &middot; {{ __('Unit :number', ['number' => $concern->lease->unit->unit_number]) }}
                                </flux:text>
                            </div>
                            <flux:badge :color="$concern->priority->color()" size="sm">{{ $concern->priority->label() }}</flux:badge>
                        </div>
                    @empty
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No urgent or high priority requests are open.') }}</flux:text>
                    @endforelse
                </div>

                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700" data-test="unanswered-complaints">
                    <div class="flex items-baseline justify-between gap-4">
                        <flux:heading>{{ __('Complaints waiting for a reply') }}</flux:heading>
                        <flux:link :href="route('landlord.complaints')" wire:navigate>{{ __('See all') }}</flux:link>
                    </div>

                    @forelse ($this->unansweredComplaints->take(5) as $concern)
                        <div wire:key="complaint-{{ $concern->id }}" class="flex items-start justify-between gap-3">
                            <div>
                                <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">{{ $concern->title }}</flux:text>
                                <flux:text class="text-zinc-500 dark:text-zinc-400">
                                    {{ $concern->lease->tenant->name }} &middot; {{ __('Unit :number', ['number' => $concern->lease->unit->unit_number]) }}
                                </flux:text>
                            </div>
                            <flux:text class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $concern->created_at->diffForHumans() }}</flux:text>
                        </div>
                    @empty
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Every complaint has had a reply.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="flex flex-col gap-4" aria-labelledby="occupancy-heading">
            <div class="flex items-baseline justify-between gap-4">
                <flux:heading id="occupancy-heading" size="lg">{{ __('Occupancy') }}</flux:heading>
                <flux:link :href="route('properties')" wire:navigate>{{ __('Open properties') }}</flux:link>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" data-test="occupancy">
                @foreach ([
                    [__('Occupied'), $this->occupancy['occupied']],
                    [__('Vacant'), $this->occupancy['vacant']],
                    [__('Under maintenance'), $this->occupancy['maintenance']],
                    [__('Open slots'), $this->occupancy['openSlots']],
                ] as [$label, $value])
                    <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
                        <flux:heading size="xl">{{ $value }}</flux:heading>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="flex flex-col gap-4" aria-labelledby="more-heading">
            <flux:heading id="more-heading" size="lg">{{ __('Everything else') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-nav-card
                    icon="building-office-2"
                    :title="__('Properties')"
                    :description="__('Manage your properties and units')"
                    :href="route('properties')"
                />
                <x-nav-card
                    icon="users"
                    :title="__('Tenants')"
                    :description="__('See who\'s renting and assign units')"
                    :href="route('tenants')"
                />
                <x-nav-card
                    icon="megaphone"
                    :title="__('Announcements')"
                    :description="__('Post updates for your tenants')"
                    :href="route('announcements')"
                />
            </div>
        </section>
    @else
        <div class="grid gap-4 sm:grid-cols-2">
            <x-nav-card
                icon="banknotes"
                :title="__('Billing')"
                :description="__('Check your balance, due date, and payment history')"
                :href="route('billing')"
            />
            <x-nav-card
                icon="wrench-screwdriver"
                :title="__('Maintenance')"
                :description="__('Submit and track a repair request')"
                :href="route('maintenance')"
            />
            <x-nav-card
                icon="flag"
                :title="__('Complaints')"
                :description="__('Raise a concern and follow its status')"
                :href="route('complaints')"
            />
            <x-nav-card
                icon="megaphone"
                :title="__('Announcements')"
                :description="__('See notices from your landlord')"
                :href="route('announcements')"
            />
        </div>
    @endif

    @if ($this->currentLease)
        <div class="flex items-center justify-between rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <div>
                <flux:heading size="sm">{{ __('Your unit') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ $this->currentLease->unit->property->name }}
                    &mdash; {{ __('Unit :number', ['number' => $this->currentLease->unit->unit_number]) }}
                    @if ($this->currentLease->currentRent)
                        &middot; &#8369;{{ number_format((float) $this->currentLease->currentRent->amount, 2) }}/mo
                    @endif
                </flux:text>
            </div>

            <flux:button variant="danger" size="sm" wire:click="confirmLeaveUnit">
                {{ __('Leave unit') }}
            </flux:button>
        </div>

        <flux:modal name="leave-unit-modal" class="max-w-md md:min-w-md" @close="closeLeaveModal" wire:model="showLeaveModal">
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Leave unit') }}</flux:heading>
                    <flux:text>{{ __('This ends your lease and frees up your spot. If other tenants remain, rent is re-split among them.') }}</flux:text>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button variant="outline" wire:click="closeLeaveModal">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="danger" wire:click="leaveUnit">{{ __('Leave unit') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
