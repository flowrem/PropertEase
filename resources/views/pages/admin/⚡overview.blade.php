<?php

use App\Enums\ListingStatus;
use App\Models\Team;
use App\Models\UnitListing;
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
        <x-nav-card
            icon="home-modern"
            :title="__('Listings')"
            :description="collect(ListingStatus::cases())->map(fn (ListingStatus $status) => ($this->listingCounts[$status->value] ?? 0).' '.strtolower($status->label()))->implode(' · ')"
            :href="route('admin.listings')"
        />
    </div>
</div>
