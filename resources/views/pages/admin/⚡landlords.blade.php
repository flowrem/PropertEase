<?php

use App\Actions\Landlords\ApproveLandlord;
use App\Actions\Landlords\RejectLandlord;
use App\Enums\TeamRole;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Landlords')] class extends Component
{
    public ?int $rejectingTeamId = null;

    public string $rejectReason = '';

    /**
     * Landlord teams whose ID is waiting for review, oldest first.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function awaitingReview(): Collection
    {
        return $this->withOwner(Team::query()
            ->whereNull('approved_at')
            ->whereNull('rejected_at')
            ->oldest('verification_submitted_at'))
            ->get();
    }

    /**
     * Every other landlord team, newest first, with its owner and property count.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function teams(): Collection
    {
        return $this->withOwner(Team::query()
            ->where(fn ($query) => $query->whereNotNull('approved_at')->orWhereNotNull('rejected_at'))
            ->latest())
            ->get();
    }

    public function approve(int $teamId, ApproveLandlord $approve): void
    {
        $approve->handle(Team::findOrFail($teamId), Auth::user());

        unset($this->awaitingReview, $this->teams);

        Flux::toast(variant: 'success', text: __('Landlord approved. They were emailed.'));
    }

    public function startRejecting(int $teamId): void
    {
        $this->rejectingTeamId = $teamId;
        $this->rejectReason = '';
        $this->resetErrorBag();
    }

    public function cancelRejecting(): void
    {
        $this->reset('rejectingTeamId', 'rejectReason');
        $this->resetErrorBag();
    }

    public function reject(RejectLandlord $reject): void
    {
        abort_unless($this->rejectingTeamId, 404);

        $reject->handle(Team::findOrFail($this->rejectingTeamId), Auth::user(), $this->rejectReason);

        $this->cancelRejecting();
        unset($this->awaitingReview, $this->teams);

        Flux::toast(variant: 'success', text: __('Landlord rejected. They were emailed.'));
    }

    /**
     * @param  Builder<Team>  $query
     * @return Builder<Team>
     */
    protected function withOwner(Builder $query): Builder
    {
        return $query
            ->withCount('properties')
            ->with(['memberships' => fn ($memberships) => $memberships
                ->where('role', TeamRole::Owner->value)
                ->with('user'),
            ]);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Landlords') }}</flux:heading>
        <flux:subheading>{{ __('Every landlord team registered on the platform.') }}</flux:subheading>
    </div>

    <div class="space-y-3">
        <flux:heading size="sm">{{ __('Awaiting review') }} ({{ $this->awaitingReview->count() }})</flux:heading>

        @forelse ($this->awaitingReview as $team)
            @php($owner = $team->memberships->first()?->user)
            <div wire:key="review-{{ $team->id }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="sm">{{ $team->name }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ $owner?->name }} &middot; {{ $owner?->email }}
                        </flux:text>
                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                            {{ __('Submitted :time', ['time' => $team->verification_submitted_at?->diffForHumans()]) }}
                        </flux:text>
                    </div>

                    @if ($team->verification_id_path)
                        <a href="{{ route('admin.landlords.id', ['team' => $team->id]) }}" target="_blank" rel="noopener" class="shrink-0">
                            <flux:button size="sm" icon="identification">{{ __('View ID') }}</flux:button>
                        </a>
                    @endif
                </div>

                @if ($rejectingTeamId === $team->id)
                    <div class="space-y-3">
                        <flux:textarea wire:model="rejectReason" :label="__('Reason (sent to the landlord)')" rows="3" required />
                        <flux:error name="reason" />
                        <flux:error name="team" />

                        <div class="flex justify-end gap-2">
                            <flux:button wire:click="cancelRejecting">{{ __('Back') }}</flux:button>
                            <flux:button variant="danger" wire:click="reject">{{ __('Reject') }}</flux:button>
                        </div>
                    </div>
                @else
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="startRejecting({{ $team->id }})">{{ __('Reject') }}</flux:button>
                        <flux:button variant="primary" wire:click="approve({{ $team->id }})" wire:confirm="{{ __('Approve this landlord? Check the ID first.') }}">{{ __('Approve') }}</flux:button>
                    </div>
                @endif
            </div>
        @empty
            <flux:text class="text-zinc-400">{{ __('No landlords are waiting for review.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-3">
        <flux:heading size="sm">{{ __('All landlords') }}</flux:heading>

        @forelse ($this->teams as $team)
            @php($owner = $team->memberships->first()?->user)
            <div wire:key="team-{{ $team->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="sm">{{ $team->name }}</flux:heading>
                        @if ($team->isRejected())
                            <flux:badge size="sm" color="red">{{ __('Rejected') }}</flux:badge>
                        @endif
                    </div>
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
</div>
