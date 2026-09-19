<?php

use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Home')] class extends Component
{
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
}; ?>

<div class="flex w-full flex-col gap-6">
    <livewire:pages::teams.pending-invitations-modal />

    <div>
        <flux:heading size="xl" level="1">
            {{ __('Welcome back, :name', ['name' => explode(' ', auth()->user()->name)[0]]) }}
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
                icon="inbox"
                :title="__('Inbox')"
                :description="__('Review maintenance requests and complaints')"
                :href="route('inbox')"
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
</div>
