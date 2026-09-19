<?php

use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Enums\UnitStatus;
use App\Models\Lease;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\Unit;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tenants')] class extends Component
{
    public ?int $assigningTenantId = null;

    public ?int $unit_id = null;

    public string $amount = '';

    public int $due_day = 1;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function tenants(): Collection
    {
        return $this->team->members()
            ->wherePivot('role', TeamRole::Tenant->value)
            ->get();
    }

    /**
     * @return Collection<int, TeamInvitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->team->invitations()
            ->where('role', TeamRole::Tenant)
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function vacantUnits(): Collection
    {
        return Unit::whereHas('property', fn ($query) => $query->where('team_id', $this->team->id))
            ->where('status', UnitStatus::Vacant)
            ->with('property')
            ->get();
    }

    public function leaseFor(User $tenant): ?Lease
    {
        return $tenant->leases()
            ->whereHas('unit.property', fn ($query) => $query->where('team_id', $this->team->id))
            ->with('unit')
            ->latest()
            ->first();
    }

    public function startAssigning(int $tenantId): void
    {
        $this->assigningTenantId = $tenantId;
        $this->reset('unit_id', 'amount');
        $this->due_day = 1;
    }

    public function cancelAssigning(): void
    {
        $this->assigningTenantId = null;
    }

    public function assignUnit(): void
    {
        $validated = $this->validate([
            'unit_id' => ['required', Rule::exists('units', 'id')],
            'amount' => ['required', 'numeric', 'min:0'],
            'due_day' => ['required', 'integer', 'min:1', 'max:28'],
        ]);

        $unit = Unit::findOrFail($validated['unit_id']);

        abort_unless($unit->property->team_id === $this->team->id, 403);

        $lease = $unit->leases()->create([
            'tenant_id' => $this->assigningTenantId,
            'start_date' => now(),
            'due_day' => $validated['due_day'],
            'status' => LeaseStatus::Active,
        ]);

        $lease->rents()->create([
            'amount' => $validated['amount'],
            'effective_date' => now(),
        ]);

        $unit->update(['status' => UnitStatus::Occupied]);

        $this->assigningTenantId = null;

        Flux::toast(variant: 'success', text: __('Unit assigned.'));

        unset($this->vacantUnits);
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

    @forelse ($this->tenants as $tenant)
        <div wire:key="tenant-{{ $tenant->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="sm">{{ $tenant->name }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $tenant->email }}</flux:text>
                </div>

                @php($lease = $this->leaseFor($tenant))

                @if ($lease)
                    <flux:badge color="blue">{{ __('Unit :number', ['number' => $lease->unit->unit_number]) }}</flux:badge>
                @else
                    <flux:badge color="zinc">{{ __('No unit assigned') }}</flux:badge>
                @endif
            </div>

            @if (! $lease)
                <div class="mt-4">
                    @if ($assigningTenantId === $tenant->id)
                        <form wire:submit="assignUnit" class="flex flex-col gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:select wire:model="unit_id" :label="__('Unit')" required>
                                <flux:select.option value="">{{ __('Select a vacant unit') }}</flux:select.option>
                                @foreach ($this->vacantUnits as $unit)
                                    <flux:select.option value="{{ $unit->id }}">{{ $unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $unit->unit_number]) }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input wire:model="amount" type="number" step="0.01" min="0" :label="__('Monthly rent')" required />
                                <flux:input wire:model="due_day" type="number" min="1" max="28" :label="__('Due day of month')" required />
                            </div>

                            <div class="flex justify-end gap-2">
                                <flux:button variant="filled" wire:click="cancelAssigning">{{ __('Cancel') }}</flux:button>
                                <flux:button type="submit" variant="primary">{{ __('Assign unit') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <flux:button variant="filled" size="sm" wire:click="startAssigning({{ $tenant->id }})">
                            {{ __('Assign a unit') }}
                        </flux:button>
                    @endif
                </div>
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

    <livewire:pages::teams.invite-member-modal
        :team="$this->team"
        default-role="tenant"
        :roles="[['value' => 'tenant', 'label' => __('Tenant')]]"
        redirect-to="tenants"
    />
</section>
