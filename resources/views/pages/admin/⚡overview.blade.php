<?php

use App\Models\Team;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admin Overview')] class extends Component
{
    #[Computed]
    public function landlordCount(): int
    {
        return Team::count();
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Admin overview') }}</flux:heading>
        <flux:subheading>{{ __('Platform-wide activity across every landlord team.') }}</flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-nav-card
            icon="building-office-2"
            :title="__('Landlords')"
            :description="__(':count landlord team(s) on the platform', ['count' => $this->landlordCount])"
            :href="route('admin.landlords')"
        />
    </div>
</div>
