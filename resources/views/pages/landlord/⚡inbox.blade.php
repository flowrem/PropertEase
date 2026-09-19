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

new #[Title('Inbox')] class extends Component
{
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Collection<int, Concern>
     */
    #[Computed]
    public function concerns(): Collection
    {
        return Concern::whereHas('lease.unit.property', fn ($query) => $query->where('team_id', $this->team->id))
            ->with(['lease.tenant', 'lease.unit'])
            ->latest()
            ->get();
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Inbox') }}</flux:heading>
        <flux:subheading>{{ __('Maintenance requests and complaints from every tenant under :team.', ['team' => $this->team->name]) }}</flux:subheading>
    </div>

    @forelse ($this->concerns as $concern)
        <div wire:key="concern-{{ $concern->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <flux:badge :color="$concern->category === ConcernCategory::Maintenance ? 'blue' : 'amber'" size="sm">
                            {{ $concern->category->label() }}
                        </flux:badge>
                        <flux:badge :color="$concern->status === ConcernStatus::Resolved ? 'lime' : ($concern->status === ConcernStatus::InProgress ? 'blue' : 'zinc')" size="sm">
                            {{ $concern->status->label() }}
                        </flux:badge>
                    </div>
                    <flux:heading size="sm" class="mt-2">{{ $concern->title }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ $concern->lease->tenant->name }} &middot; {{ __('Unit :number', ['number' => $concern->lease->unit->unit_number]) }}
                    </flux:text>
                </div>
                <flux:text class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">{{ $concern->created_at->diffForHumans() }}</flux:text>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('Nothing here yet') }}</flux:heading>
            <flux:subheading>{{ __('Maintenance requests and complaints your tenants submit will show up here.') }}</flux:subheading>
        </div>
    @endforelse
</section>
