<?php

use App\Actions\Leases\MoveTenantIntoUnit;
use App\Enums\BillingTiming;
use App\Enums\LeaseStatus;
use App\Enums\ReservationStatus;
use App\Enums\StayType;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Reservation;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\Unit;
use App\Models\User;
use App\Rules\PhilippineMobileNumber;
use Flux\Flux;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tenants')] class extends Component
{
    public bool $showManageModal = false;

    /**
     * The tenant open in the manage modal, kept in the address so a
     * reservation's "Move in" button can link straight to them.
     */
    #[Url(as: 'tenant')]
    public ?int $managingTenantId = null;

    public ?int $unit_id = null;

    public int $due_day = 1;

    public string $billing_timing = 'advance';

    public string $stay_type = '';

    /**
     * Stay type of the tenant's current lease, changed without moving them.
     */
    public string $currentLeaseStayType = '';

    /**
     * Assign the tenant even though the unit has no clean move-in check.
     * Only the landlord and managers can, and they must say why.
     */
    public bool $proceedAnyway = false;

    public string $overrideReason = '';

    public string $search = '';

    public bool $showRemoveModal = false;

    public ?int $removingLeaseId = null;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Base query for tenant members on this team, with their active lease on
     * this team's units eager loaded.
     *
     * @return BelongsToMany<User, Team>
     */
    protected function teamTenantsQuery(): BelongsToMany
    {
        return $this->team->members()
            ->wherePivot('role', TeamRole::Tenant->value)
            ->with(['leases' => fn ($leases) => $leases
                ->where('status', LeaseStatus::Active->value)
                ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
                ->with(['unit.property', 'currentRent', 'contract'])
                ->latest(),
            ]);
    }

    /**
     * Tenants on the current team, each with their active lease on this team's
     * units eager loaded, filtered by name, email, property, or unit number
     * when searching.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function tenants(): Collection
    {
        $tenants = $this->teamTenantsQuery()->get();

        $search = trim($this->search);

        if ($search === '') {
            return $tenants;
        }

        return $tenants->filter(function (User $tenant) use ($search) {
            $lease = $tenant->leases->first();

            return Str::contains($tenant->name, $search, ignoreCase: true)
                || Str::contains($tenant->email, $search, ignoreCase: true)
                || ($lease && Str::contains($lease->unit->property->name, $search, ignoreCase: true))
                || ($lease && Str::contains($lease->unit->unit_number, $search, ignoreCase: true));
        })->values();
    }

    /**
     * The tenant currently open in the manage modal, with their active lease
     * on this team's units eager loaded.
     */
    #[Computed]
    public function managingTenant(): ?User
    {
        if (! $this->managingTenantId) {
            return null;
        }

        return $this->teamTenantsQuery()->find($this->managingTenantId);
    }

    /**
     * @return Collection<int, TeamInvitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->team->invitations()
            ->pending()
            ->where('role', TeamRole::Tenant)
            ->latest()
            ->get();
    }

    /**
     * Units that can still take a tenant: either genuinely vacant, or units that
     * allow multiple tenants and haven't reached their tenant limit yet.
     *
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function vacantUnits(): Collection
    {
        return Unit::whereHas('property', fn ($query) => $query->where('team_id', $this->team->id))
            ->where(fn ($query) => $query
                ->where('status', UnitStatus::Vacant)
                ->orWhere('allows_multiple_tenants', true))
            ->withCount(['activeLeases', 'heldReservations'])->withBedSpaces()
            ->with('property')
            ->get()
            ->filter(fn (Unit $unit) => $unit->hasRoomForAnotherTenant())
            ->values();
    }

    /**
     * Units this tenant can be assigned or moved to: everywhere with room,
     * minus the unit they're already renting.
     *
     * @return Collection<int, Unit>
     */
    public function assignableUnits(?Lease $currentLease, ?User $tenant = null): Collection
    {
        $units = $this->vacantUnits;

        $heldReservation = $tenant ? $this->confirmedReservationFor($tenant) : null;

        if ($heldReservation && $units->doesntContain('id', $heldReservation->unit_id)) {
            $units = $units->push(
                Unit::withCount(['activeLeases', 'heldReservations'])->with('property')->findOrFail($heldReservation->unit_id),
            );
        }

        return $units
            ->reject(fn (Unit $unit) => $currentLease && $unit->id === $currentLease->unit_id)
            ->values();
    }

    /**
     * The confirmed reservation on this team that is holding a slot for the tenant, if any.
     */
    protected function confirmedReservationFor(User $tenant): ?Reservation
    {
        return Reservation::query()
            ->where('team_id', $this->team->id)
            ->where('tenant_user_id', $tenant->id)
            ->where('status', ReservationStatus::Confirmed->value)
            ->first();
    }

    /**
     * Open the tenant named in the address, if they are one of this team's.
     */
    public function mount(): void
    {
        if ($this->managingTenantId && $this->managingTenant) {
            $this->manageTenant($this->managingTenantId);
        } else {
            $this->managingTenantId = null;
        }
    }

    /**
     * Open the manage modal. A tenant holding a reservation starts with the
     * reserved unit picked, so moving them in is one click.
     */
    public function manageTenant(int $tenantId): void
    {
        $this->managingTenantId = $tenantId;
        $this->showManageModal = true;

        $lease = $this->managingTenant?->leases->first();
        $heldReservation = $this->managingTenant ? $this->confirmedReservationFor($this->managingTenant) : null;

        $this->unit_id = $heldReservation && $heldReservation->unit_id !== $lease?->unit_id ? $heldReservation->unit_id : null;
        $this->due_day = $lease?->due_day ?? 1;
        $this->billing_timing = $lease?->billing_timing->value ?? BillingTiming::Advance->value;
        $this->currentLeaseStayType = $lease?->stay_type?->value ?? '';
        $this->stay_type = $lease?->stay_type?->value ?? $heldReservation?->stay_type?->value ?? '';
    }

    /**
     * The unit the tenant being managed has reserved, if any.
     */
    #[Computed]
    public function reservedUnitId(): ?int
    {
        return $this->managingTenant ? $this->confirmedReservationFor($this->managingTenant)?->unit_id : null;
    }

    /**
     * Change the stay type on the tenant's current lease without moving them.
     */
    public function updatedCurrentLeaseStayType(): void
    {
        $lease = $this->managingTenant?->leases->first();

        abort_unless($lease, 404);

        $this->validate(['currentLeaseStayType' => ['nullable', Rule::enum(StayType::class)]]);

        $lease->update(['stay_type' => StayType::tryFrom($this->currentLeaseStayType)]);

        Flux::toast(variant: 'success', text: __('Stay type updated.'));
    }

    public function closeManageModal(): void
    {
        $this->showManageModal = false;
        $this->managingTenantId = null;
        $this->reset('unit_id', 'due_day', 'billing_timing', 'stay_type', 'currentLeaseStayType', 'proceedAnyway', 'overrideReason');
    }

    /**
     * A reason to proceed past one unit's move-in check doesn't carry over
     * to another unit picked afterwards.
     */
    public function updatedUnitId(): void
    {
        $this->reset('proceedAnyway', 'overrideReason');
        $this->resetValidation();
    }

    /**
     * Whether the current user may let a tenant move in past a missing or
     * failed move-in check (the landlord and managers).
     */
    #[Computed]
    public function canOverrideMoveInCheck(): bool
    {
        return Auth::user()->canManageListingsOn($this->team);
    }

    /**
     * Assign a unit to the tenant being managed, or move them to a different
     * one if they already have an active lease. Either way, rent on the
     * affected unit(s) is re-split among whoever remains afterward, and the
     * new lease gets its contract from the landlord's current terms.
     */
    public function saveUnitAssignment(MoveTenantIntoUnit $moveTenantIntoUnit): void
    {
        $tenant = $this->managingTenant;

        abort_unless($tenant, 404);

        $validated = $this->validate([
            'unit_id' => ['required', Rule::exists('units', 'id')],
            'due_day' => ['required', 'integer', 'min:1', 'max:28'],
            'billing_timing' => ['required', Rule::enum(BillingTiming::class)],
            'stay_type' => ['nullable', Rule::enum(StayType::class)],
        ]);

        $unit = Unit::withCount('activeLeases')->findOrFail($validated['unit_id']);

        abort_unless($unit->property->team_id === $this->team->id, 403);

        $heldReservation = $this->confirmedReservationFor($tenant);
        $holdsThisUnit = $heldReservation?->unit_id === $unit->id;

        abort_unless($unit->hasRoomForAnotherTenant(excludingOwnHold: $holdsThisUnit), 403);

        $moveInCheck = $unit->pendingMoveInCheck();
        $moveInCheckPasses = $moveInCheck !== null && $moveInCheck->blockingItemCount() === 0;

        if (! $moveInCheckPasses) {
            if (! $this->proceedAnyway) {
                $this->addError('unit_id', $moveInCheck
                    ? __('This unit\'s move-in check found items not working or missing.')
                    : __('Record a move-in check for this unit first.'));

                return;
            }

            Gate::authorize('manageInventory', $unit);

            $this->validate(
                ['overrideReason' => ['required', 'string', 'min:10', 'max:500']],
                attributes: ['overrideReason' => __('reason')],
            );
        }

        $currentLease = $tenant->leases->first();

        $moveTenantIntoUnit->handle(
            $tenant,
            $unit,
            (int) $validated['due_day'],
            BillingTiming::from($validated['billing_timing']),
            StayType::tryFrom((string) $validated['stay_type']),
            Auth::user(),
            overrideReason: $moveInCheckPasses ? null : $this->overrideReason,
            holdsSlotOnUnit: $holdsThisUnit,
        );

        $this->closeManageModal();

        unset($this->tenants, $this->vacantUnits);

        Flux::toast(variant: 'success', text: $currentLease ? __('Tenant moved.') : __('Unit assigned.'));
    }

    public function confirmRemoveTenant(int $leaseId): void
    {
        $this->removingLeaseId = $this->teamLease($leaseId)->id;
        $this->showManageModal = false;
        $this->showRemoveModal = true;
    }

    public function removeTenant(): void
    {
        if (! $this->removingLeaseId) {
            return;
        }

        $this->teamLease($this->removingLeaseId)->end(LeaseStatus::Terminated);

        $this->managingTenantId = null;
        $this->closeRemoveModal();

        unset($this->tenants, $this->vacantUnits);

        Flux::toast(variant: 'success', text: __('Tenant removed from unit.'));
    }

    public function closeRemoveModal(): void
    {
        $this->showRemoveModal = false;
        $this->removingLeaseId = null;
    }

    /**
     * Find a lease on the current team, or fail with a 404.
     */
    protected function teamLease(int $leaseId): Lease
    {
        return Lease::whereHas('unit.property', fn ($query) => $query->where('team_id', $this->team->id))
            ->findOrFail($leaseId);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Tenants') }}</flux:heading>
            <flux:subheading>{{ __('Everyone renting a unit under :team.', ['team' => $this->team->name]) }}</flux:subheading>
        </div>

        <flux:modal.trigger name="invite-member">
            <flux:button variant="primary" icon="user-plus">{{ __('Invite a tenant') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:input
        wire:model.live.debounce.300ms="search"
        :label="__('Search')"
        :placeholder="__('Search by name, email, property, or unit')"
        clearable
    />

    @forelse ($this->tenants as $tenant)
        <div
            wire:key="tenant-{{ $tenant->id }}"
            wire:click="manageTenant({{ $tenant->id }})"
            class="flex cursor-pointer items-center justify-between rounded-lg border border-zinc-200 p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
        >
            <div>
                <flux:heading size="sm">{{ $tenant->name }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $tenant->email }}</flux:text>
                @if ($tenant->contact_number)
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ PhilippineMobileNumber::forDisplay($tenant->contact_number) }}</flux:text>
                @endif
            </div>

            @php($lease = $tenant->leases->first())

            @if ($lease)
                <div class="flex flex-col gap-1 text-right">
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $lease->unit->property->name }}</flux:text>
                    <flux:badge color="blue" class="justify-center text-center">{{ __('Unit :number', ['number' => $lease->unit->unit_number]) }}</flux:badge>
                    @if ($lease->currentRent)
                        <flux:text class="text-right text-xs text-zinc-500 dark:text-zinc-500">
                            &#8369;{{ number_format((float) $lease->currentRent->amount, 2) }}/mo
                        </flux:text>
                    @endif
                </div>
            @else
                <flux:badge color="zinc">{{ __('No unit assigned') }}</flux:badge>
            @endif
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('No tenants yet') }}</flux:heading>
            <flux:subheading>{{ __('Invite a tenant by email to get them set up.') }}</flux:subheading>
            <flux:modal.trigger name="invite-member">
                <flux:button variant="primary" class="mt-4">{{ __('Invite a tenant') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @endforelse

    @if ($this->pendingInvitations->isNotEmpty())
        <div>
            <flux:heading size="sm" class="mb-2">{{ __('Pending invites') }}</flux:heading>
            <ul class="grid gap-2">
                @foreach ($this->pendingInvitations as $invitation)
                    <li wire:key="invite-{{ $invitation->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-zinc-700">
                        <span>{{ $invitation->email }}</span>
                        <flux:badge color="zinc" size="sm">{{ __('Pending') }}</flux:badge>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <flux:modal name="manage-tenant-modal" class="max-w-lg" @close="closeManageModal" wire:model="showManageModal">
        @if ($this->managingTenant)
            @php($currentLease = $this->managingTenant->leases->first())

            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $this->managingTenant->name }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $this->managingTenant->email }}</flux:text>
                    @if ($this->managingTenant->contact_number)
                        <flux:link href="tel:{{ $this->managingTenant->contact_number }}" class="text-sm">{{ PhilippineMobileNumber::forDisplay($this->managingTenant->contact_number) }}</flux:link>
                    @else
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No mobile number yet') }}</flux:text>
                    @endif
                </div>

                @if ($currentLease)
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Currently renting') }}</flux:text>
                        <flux:heading size="sm">
                            {{ $currentLease->unit->property->name }}
                            &mdash; {{ __('Unit :number', ['number' => $currentLease->unit->unit_number]) }}
                        </flux:heading>
                        @if ($currentLease->currentRent)
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-500">
                                &#8369;{{ number_format((float) $currentLease->currentRent->amount, 2) }}/mo
                            </flux:text>
                        @endif

                        <div class="mt-2 flex items-center gap-2 text-sm">
                            <flux:text>{{ __('Contract:') }}</flux:text>
                            @if (! $currentLease->contract)
                                <flux:badge size="sm" color="zinc">{{ __('Not made yet') }}</flux:badge>
                            @elseif ($currentLease->contract->isAccepted())
                                <flux:badge size="sm" color="lime">{{ __('Agreed') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('Waiting for the tenant') }}</flux:badge>
                            @endif
                            <flux:link :href="route('leases.contract', ['lease' => $currentLease])" wire:navigate>{{ __('View') }}</flux:link>
                        </div>

                        <flux:select wire:model.live="currentLeaseStayType" :label="__('Stay')" size="sm" class="mt-3">
                            <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                            @foreach (StayType::cases() as $stayType)
                                <flux:select.option value="{{ $stayType->value }}">{{ __($stayType->label()) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif

                <form wire:submit="saveUnitAssignment" class="flex flex-col gap-4">
                    <flux:select wire:model.live="unit_id" :label="$currentLease ? __('Move to unit') : __('Assign unit')" required>
                        <flux:select.option value="">{{ __('Select a unit') }}</flux:select.option>
                        @foreach ($this->assignableUnits($currentLease, $this->managingTenant) as $unit)
                            <flux:select.option value="{{ $unit->id }}">
                                {{ $unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $unit->unit_number]) }}
                                &mdash; &#8369;{{ number_format((float) $unit->price, 2) }}/mo
                                @if ($unit->allows_multiple_tenants && $unit->tenant_limit !== null)
                                    ({{ $unit->active_leases_count }}/{{ $unit->capacity() }} {{ __('tenants') }})
                                @endif
                                @if ($unit->id === $this->reservedUnitId)
                                    &middot; {{ __('Reserved by this tenant') }}
                                @endif
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    @php($selectedUnit = $this->assignableUnits($currentLease, $this->managingTenant)->firstWhere('id', $unit_id))

                    @if ($selectedUnit)
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('This tenant will pay :amount/mo (their share of the :price/mo unit, split :count ways).', [
                                'amount' => '₱'.number_format($selectedUnit->rentSharePerTenant($selectedUnit->active_leases_count + 1), 2),
                                'price' => '₱'.number_format((float) $selectedUnit->price, 2),
                                'count' => $selectedUnit->active_leases_count + 1,
                            ]) }}
                        </flux:text>

                        @php($moveInCheck = $selectedUnit->pendingMoveInCheck())
                        @php($blockingItems = $moveInCheck?->blockingItemCount() ?? 0)

                        @if ($moveInCheck && $blockingItems === 0)
                            <flux:callout icon="check-circle" color="green">
                                <flux:callout.text>
                                    {{ __('Move-in check recorded on :date. The tenant will be asked to acknowledge it.', ['date' => $moveInCheck->checked_at->format('M j, Y')]) }}
                                </flux:callout.text>
                            </flux:callout>
                        @else
                            <flux:callout icon="exclamation-triangle" :color="$moveInCheck ? 'red' : 'amber'">
                                <flux:callout.text>
                                    {{ $moveInCheck
                                        ? trans_choice('The move-in check on :date found :count item not working or missing.|The move-in check on :date found :count items not working or missing.', $blockingItems, ['date' => $moveInCheck->checked_at->format('M j, Y')])
                                        : __('This unit has no move-in check since its last tenant left.') }}
                                    <flux:link :href="route('units.inventory', ['unit' => $selectedUnit])" wire:navigate>{{ __('Open the unit\'s checks') }}</flux:link>
                                </flux:callout.text>
                            </flux:callout>

                            @if ($this->canOverrideMoveInCheck)
                                <flux:checkbox wire:model.live="proceedAnyway" :label="__('Proceed anyway')" :description="__('Assign the tenant without a clean move-in check. Your reason is kept on the lease.')" />

                                @if ($proceedAnyway)
                                    <flux:textarea wire:model="overrideReason" :label="__('Reason')" rows="2" maxlength="500" :placeholder="__('The tenant agreed to move in while the faucet is being replaced this week.')" required />
                                @endif
                            @else
                                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Only the landlord or a manager can assign a tenant without a clean move-in check.') }}</flux:text>
                            @endif
                        @endif
                    @endif

                    <flux:select wire:model="stay_type" :label="__('Stay')">
                        <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                        @foreach (StayType::cases() as $stayType)
                            <flux:select.option value="{{ $stayType->value }}">{{ __($stayType->label()) }} &middot; {{ __($stayType->description()) }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="due_day" type="number" min="1" max="28" :label="__('Due day of month')" required />

                    <flux:radio.group wire:model="billing_timing" :label="__('Billing timing')" variant="segmented">
                        <flux:radio value="advance">{{ __('Advance') }}</flux:radio>
                        <flux:radio value="arrears">{{ __('Arrears') }}</flux:radio>
                    </flux:radio.group>
                    <div class="flex flex-col gap-0.5">
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Advance') }}</span>
                            — {{ __('tenant pays before the period starts.') }}
                        </flux:text>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ __('Arrears') }}</span>
                            — {{ __('tenant pays after the period ends.') }}
                        </flux:text>
                    </div>

                    <div class="flex items-center justify-between gap-2">
                        <div>
                            @if ($currentLease)
                                <flux:button variant="danger" wire:click="confirmRemoveTenant({{ $currentLease->id }})">
                                    {{ __('Remove tenant') }}
                                </flux:button>
                            @endif
                        </div>

                        <div class="flex gap-2">
                            <flux:button variant="filled" wire:click="closeManageModal">{{ __('Cancel') }}</flux:button>
                            <flux:button type="submit" variant="primary">
                                {{ $currentLease ? __('Move tenant') : __('Assign unit') }}
                            </flux:button>
                        </div>
                    </div>
                </form>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="remove-tenant-modal" class="max-w-md md:min-w-md" @close="closeRemoveModal" wire:model="showRemoveModal">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove tenant') }}</flux:heading>
                <flux:text>{{ __('This ends their lease and frees up their spot on the unit. Rent is re-split among whoever remains, or the unit goes back to vacant if they were the last one.') }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="outline" wire:click="closeRemoveModal">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="removeTenant">{{ __('Remove tenant') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <livewire:pages::teams.invite-member-modal
        :team="$this->team"
        default-role="tenant"
        :roles="[['value' => 'tenant', 'label' => __('Tenant')]]"
        redirect-to="tenants"
    />
</section>
