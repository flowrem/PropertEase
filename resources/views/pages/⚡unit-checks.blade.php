<?php

use App\Enums\ConditionCheckKind;
use App\Enums\LeaseStatus;
use App\Models\ConditionCheck;
use App\Models\Lease;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Unit checks')] class extends Component
{
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The tenant's active lease on this team, with the move-in check it
     * claimed and that check's items.
     */
    #[Computed]
    public function lease(): ?Lease
    {
        return Auth::user()->leases()
            ->where('status', LeaseStatus::Active->value)
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['unit.property', 'moveInCheck.items.unitItem', 'moveInCheck.checker'])
            ->latest()
            ->first();
    }

    /**
     * Routine checks of the tenant's unit recorded since they moved in,
     * newest first. Checks from before their stay are not theirs to see.
     *
     * @return EloquentCollection<int, ConditionCheck>
     */
    #[Computed]
    public function routineChecks(): EloquentCollection
    {
        if (! $this->lease) {
            return new EloquentCollection;
        }

        return $this->lease->unit->conditionChecks()
            ->where('kind', ConditionCheckKind::Routine->value)
            ->where('checked_at', '>=', $this->lease->start_date->startOfDay())
            ->with(['items.unitItem', 'checker'])
            ->latest('checked_at')
            ->get();
    }

    /**
     * Agree that the checklist shows the unit's condition when moving in.
     */
    public function acknowledge(): void
    {
        $check = $this->lease?->moveInCheck;

        abort_unless($check, 404);

        Gate::authorize('acknowledge', $check);

        $check->acknowledge();

        unset($this->lease);

        Flux::toast(variant: 'success', text: __('Thanks. Your acknowledgment is recorded.'));
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Unit checks') }}</flux:heading>
        <flux:subheading>{{ __('The condition of everything in your unit, as your landlord recorded it when you moved in and during your stay.') }}</flux:subheading>
    </div>

    @php($check = $this->lease?->moveInCheck)

    @if ($this->lease)
        <flux:heading size="lg" level="2">
            {{ __('Move-in checklist') }}
            <span class="font-normal text-zinc-500">&middot; {{ $this->lease->unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $this->lease->unit->unit_number]) }}</span>
        </flux:heading>
    @endif

    @if (! $this->lease)
        <flux:text>{{ __('You are not renting a unit here right now.') }}</flux:text>
    @elseif (! $check)
        <flux:text>{{ __('Your landlord has not recorded a move-in checklist for your unit.') }}</flux:text>
    @else
        <div class="rounded-lg border border-zinc-200 bg-white p-4">
            <flux:text class="text-sm">
                {{ __('Checked on :date', ['date' => $check->checked_at->format('M j, Y')]) }}
                @if ($check->checker)
                    {{ __('by :name', ['name' => $check->checker->name]) }}
                @endif
            </flux:text>

            <x-check-items :check="$check" class="mt-3" />
        </div>

        @if ($check->tenant_acknowledged_at)
            <flux:callout icon="check-circle" color="green">
                <flux:callout.text>
                    {{ __('You acknowledged this checklist on :date.', ['date' => $check->tenant_acknowledged_at->format('M j, Y')]) }}
                </flux:callout.text>
            </flux:callout>
        @else
            <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:text>
                    {{ __('Go through the list. If it matches what you found, acknowledge it. At move-out, the unit can be compared against this list, so you and your landlord both know what was already worn or broken. If something is wrong, tell your landlord before acknowledging.') }}
                </flux:text>
                <flux:button variant="primary" icon="check" wire:click="acknowledge">{{ __('I acknowledge this checklist') }}</flux:button>
            </div>
        @endif
    @endif

    @if ($this->routineChecks->isNotEmpty())
        <div class="space-y-3">
            <flux:heading size="lg" level="2">{{ __('Routine checks during your stay') }}</flux:heading>

            @foreach ($this->routineChecks as $routineCheck)
                <details wire:key="routine-{{ $routineCheck->id }}" class="rounded-lg border border-zinc-200 bg-white" @if ($loop->first) open @endif>
                    <summary class="cursor-pointer px-4 py-3 text-sm font-medium">
                        {{ __('Checked on :date', ['date' => $routineCheck->checked_at->format('M j, Y')]) }}
                        @if ($routineCheck->checker)
                            <span class="font-normal text-zinc-500">{{ __('by :name', ['name' => $routineCheck->checker->name]) }}</span>
                        @endif
                    </summary>
                    <div class="border-t border-zinc-200 px-4 py-2">
                        <x-check-items :check="$routineCheck" />
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</section>
