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

new #[Title('Contract')] class extends Component
{
    public bool $hasRead = false;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The tenant's active lease on this team, with its contract.
     */
    #[Computed]
    public function lease(): ?Lease
    {
        return Auth::user()->leases()
            ->where('status', LeaseStatus::Active->value)
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['unit.property', 'contract'])
            ->latest()
            ->first();
    }

    /**
     * Agree to the contract. The time and the address it came from are
     * recorded, and it can no longer be replaced.
     */
    public function accept(): void
    {
        $contract = $this->lease?->contract;

        abort_unless($contract, 404);

        Gate::authorize('accept', $contract);

        $this->validate(['hasRead' => ['accepted']], ['hasRead.accepted' => __('Tick the box to confirm you read the contract.')]);

        $contract->accept((string) request()->ip());

        unset($this->lease);

        Flux::toast(variant: 'success', text: __('Thanks. Your agreement is recorded.'));
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Contract') }}</flux:heading>
        <flux:subheading>{{ __('The rental agreement for your unit.') }}</flux:subheading>
    </div>

    @if (! $this->lease)
        <flux:callout icon="information-circle">
            <flux:callout.text>{{ __('You don\'t have a unit with this landlord yet. Your contract appears here once you move in.') }}</flux:callout.text>
        </flux:callout>
    @elseif (! $this->lease->contract)
        <flux:callout icon="clock">
            <flux:callout.text>{{ __('Your landlord hasn\'t made your contract yet. You\'ll get a notification when it\'s ready.') }}</flux:callout.text>
        </flux:callout>
    @else
        @php($contract = $this->lease->contract)

        @if ($contract->isAccepted())
            <flux:callout icon="check-circle" color="green">
                <flux:callout.text>
                    {{ __('You agreed to this contract on :date.', ['date' => $contract->tenant_accepted_at->timezone(config('occuplace.display_timezone'))->format('M j, Y, g:i A')]) }}
                </flux:callout.text>
            </flux:callout>
        @else
            <flux:callout icon="document-text" color="amber">
                <flux:callout.text>{{ __('Please read your contract, then agree to it below. Ask your landlord first if anything looks wrong.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex justify-end">
            <flux:button size="sm" icon="printer" :href="route('contracts.print', ['contract' => $contract])" target="_blank">{{ __('Print or save as PDF') }}</flux:button>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700">
            {!! $contract->body_html !!}
        </div>

        @unless ($contract->isAccepted())
            <form wire:submit="accept" class="flex flex-col gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:checkbox wire:model="hasRead" :label="__('I have read this contract and agree to its terms.')" />
                <flux:error name="hasRead" />
                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">{{ __('I agree') }}</flux:button>
                </div>
            </form>
        @endunless
    @endif
</section>
