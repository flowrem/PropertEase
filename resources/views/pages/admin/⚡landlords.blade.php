<?php

use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Landlords')] class extends Component
{
    /**
     * Every landlord team, newest first, with its owner and property count.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function teams(): Collection
    {
        return Team::query()
            ->withCount('properties')
            ->with(['memberships' => fn ($memberships) => $memberships
                ->where('role', TeamRole::Owner->value)
                ->with('user'),
            ])
            ->latest()
            ->get();
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Landlords') }}</flux:heading>
        <flux:subheading>{{ __('Every landlord team registered on the platform.') }}</flux:subheading>
    </div>

    @forelse ($this->teams as $team)
        @php($owner = $team->memberships->first()?->user)
        <div wire:key="team-{{ $team->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div>
                <flux:heading size="sm">{{ $team->name }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ $owner?->name }} &middot; {{ $owner?->email }}
                </flux:text>
            </div>

            <div class="flex flex-col items-end gap-1">
                <flux:badge color="zinc">{{ $team->properties_count }} {{ Str::plural('property', $team->properties_count) }}</flux:badge>
                <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                    {{ __('Joined :date', ['date' => $team->created_at->format('M j, Y')]) }}
                </flux:text>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('No landlords yet') }}</flux:heading>
            <flux:subheading>{{ __('Landlord teams appear here once someone registers.') }}</flux:subheading>
        </div>
    @endforelse
</div>
