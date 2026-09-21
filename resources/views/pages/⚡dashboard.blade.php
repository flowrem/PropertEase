<?php

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\Team;
use Flux\Flux;
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
            {{ __("Here's everything available to you in :app.", ['app' => config('app.name')]) }}
        </flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @if ($this->isLandlord)
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
                icon="banknotes"
                :title="__('Invoices')"
                :description="__('Track who owes rent and record payments')"
                :href="route('invoices')"
            />
            <x-nav-card
                icon="inbox"
                :title="__('Inbox')"
                :description="__('Review maintenance requests and complaints')"
                :href="route('inbox')"
            />
            <x-nav-card
                icon="megaphone"
                :title="__('Announcements')"
                :description="__('Post updates for your tenants')"
                :href="route('announcements')"
            />
        @else
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
        @endif
    </div>

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
