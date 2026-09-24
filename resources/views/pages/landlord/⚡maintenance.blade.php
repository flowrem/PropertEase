<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernStatus;
use App\Models\Concern;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Maintenance')] class extends Component
{
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * This team's maintenance requests: unresolved first, most important
     * priority first, and oldest first within a priority.
     *
     * @return Collection<int, Concern>
     */
    #[Computed]
    public function concerns(): Collection
    {
        return Concern::where('category', ConcernCategory::Maintenance->value)
            ->whereHas('lease.unit.property', fn ($query) => $query->where('team_id', $this->team->id))
            ->with(['lease.tenant', 'lease.unit'])
            ->get()
            ->sort(fn (Concern $a, Concern $b) => [$a->status === ConcernStatus::Resolved, $b->priority->rank(), $a->created_at]
                <=> [$b->status === ConcernStatus::Resolved, $a->priority->rank(), $b->created_at])
            ->values();
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Maintenance') }}</flux:heading>
        <flux:subheading>{{ __('Repair requests from every tenant under :team, most urgent first.', ['team' => $this->team->name]) }}</flux:subheading>
    </div>

    <x-concern-list
        :concerns="$this->concerns"
        show-priority
        :empty-title="__('Nothing here yet')"
        :empty-text="__('Repair requests your tenants submit will show up here.')"
    />
</section>
