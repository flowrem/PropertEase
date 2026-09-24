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

new #[Title('Complaints')] class extends Component
{
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * This team's complaints: unresolved first, and the ones that have waited
     * longest first, so nothing sits unanswered at the bottom.
     *
     * @return Collection<int, Concern>
     */
    #[Computed]
    public function concerns(): Collection
    {
        return Concern::where('category', ConcernCategory::Complaint->value)
            ->whereHas('lease.unit.property', fn ($query) => $query->where('team_id', $this->team->id))
            ->with(['lease.tenant', 'lease.unit'])
            ->get()
            ->sort(fn (Concern $a, Concern $b) => [$a->status === ConcernStatus::Resolved, $a->created_at]
                <=> [$b->status === ConcernStatus::Resolved, $b->created_at])
            ->values();
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Complaints') }}</flux:heading>
        <flux:subheading>{{ __('Complaints from every tenant under :team, longest waiting first.', ['team' => $this->team->name]) }}</flux:subheading>
    </div>

    <x-concern-list
        :concerns="$this->concerns"
        :empty-title="__('Nothing here yet')"
        :empty-text="__('Complaints your tenants submit will show up here.')"
    />
</section>
