<?php

use App\Enums\LeaseStatus;
use App\Models\Lease;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Move-in checklist')] class extends Component
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
        <flux:heading size="xl" level="1">{{ __('Move-in checklist') }}</flux:heading>
        <flux:subheading>{{ __('The condition of everything in your unit when you moved in, as your landlord recorded it.') }}</flux:subheading>
    </div>

    @php($check = $this->lease?->moveInCheck)

    @if (! $this->lease)
        <flux:text>{{ __('You are not renting a unit here right now.') }}</flux:text>
    @elseif (! $check)
        <flux:text>{{ __('Your landlord has not recorded a move-in checklist for your unit.') }}</flux:text>
    @else
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">
                {{ $this->lease->unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $this->lease->unit->unit_number]) }}
            </flux:heading>
            <flux:text class="text-sm">
                {{ __('Checked on :date', ['date' => $check->checked_at->format('M j, Y')]) }}
                @if ($check->checker)
                    {{ __('by :name', ['name' => $check->checker->name]) }}
                @endif
            </flux:text>

            <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($check->items as $checkedItem)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <span class="text-sm font-medium">{{ $checkedItem->unitItem->name }}</span>
                            @if ($checkedItem->remarks)
                                <flux:text class="text-xs">{{ $checkedItem->remarks }}</flux:text>
                            @endif
                        </div>
                        <flux:badge size="sm" :color="$checkedItem->condition->color()">{{ $checkedItem->condition->label() }}</flux:badge>
                    </li>
                @endforeach
            </ul>

            @if ($check->notes)
                <flux:text class="mt-3">{{ $check->notes }}</flux:text>
            @endif
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
</section>
