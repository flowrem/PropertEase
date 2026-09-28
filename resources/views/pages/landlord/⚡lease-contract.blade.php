<?php

use App\Actions\Contracts\GenerateLeaseContract;
use App\Models\Lease;
use App\Models\LeaseContract;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Contract')] class extends Component
{
    #[Locked]
    public int $leaseId;

    public function mount(int $lease): void
    {
        $this->leaseId = $lease;

        // Resolve once on load so another team's lease 404s immediately.
        $this->lease;
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Resolved within the current team on every request.
     */
    #[Computed]
    public function lease(): Lease
    {
        return Lease::query()
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['tenant', 'unit.property.team', 'contract'])
            ->findOrFail($this->leaseId);
    }

    #[Computed]
    public function canGenerate(): bool
    {
        return Gate::allows('generate', [LeaseContract::class, $this->lease]);
    }

    /**
     * Make the contract from the current terms, or make it again while the
     * tenant has not agreed yet.
     */
    public function generate(GenerateLeaseContract $generateLeaseContract): void
    {
        Gate::authorize('generate', [LeaseContract::class, $this->lease]);

        $hadContract = $this->lease->contract !== null;

        $generateLeaseContract->handle($this->lease, Auth::user());

        unset($this->lease);

        Flux::toast(variant: 'success', text: $hadContract
            ? __('Contract made again from your current terms. The tenant was told.')
            : __('Contract made. The tenant was told to read and agree to it.'));
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    @php($lease = $this->lease)
    @php($contract = $lease->contract)

    <div>
        <flux:link :href="route('tenants', ['tenant' => $lease->tenant_id])" wire:navigate class="text-sm">&larr; {{ __('Tenants') }}</flux:link>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1">{{ __('Contract') }}</flux:heading>
            @if (! $contract)
                <flux:badge size="sm" color="zinc">{{ __('Not made yet') }}</flux:badge>
            @elseif ($contract->isAccepted())
                <flux:badge size="sm" color="lime">{{ __('Agreed') }}</flux:badge>
            @else
                <flux:badge size="sm" color="amber">{{ __('Waiting for the tenant') }}</flux:badge>
            @endif
        </div>
        <flux:subheading>
            {{ $lease->tenant->name }} &middot; {{ $lease->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $lease->unit->unit_number]) }}
        </flux:subheading>
    </div>

    @if (! $contract)
        <flux:callout icon="document-text">
            <flux:callout.text>{{ __('This tenant moved in before contracts were made automatically. Create one from your current contract terms.') }}</flux:callout.text>
        </flux:callout>
    @elseif ($contract->isAccepted())
        <flux:callout icon="check-circle" color="green">
            <flux:callout.text>
                {{ __('The tenant agreed on :date. It can no longer be changed.', ['date' => $contract->tenant_accepted_at->timezone(config('occuplace.display_timezone'))->format('M j, Y, g:i A')]) }}
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:callout icon="clock" color="amber">
            <flux:callout.text>
                {{ __('Made on :date. The tenant hasn\'t agreed yet. If you changed your contract terms since, you can make it again.', ['date' => $contract->generated_at->timezone(config('occuplace.display_timezone'))->format('M j, Y')]) }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="flex flex-wrap justify-end gap-2">
        @if ($this->canGenerate)
            <flux:button :href="route('contract-terms')" wire:navigate size="sm" variant="ghost">{{ __('Edit contract terms') }}</flux:button>
            <flux:button size="sm" :variant="$contract ? 'filled' : 'primary'" icon="arrow-path" wire:click="generate">
                {{ $contract ? __('Make again from current terms') : __('Create contract') }}
            </flux:button>
        @endif
        @if ($contract)
            <flux:button size="sm" icon="printer" :href="route('contracts.print', ['contract' => $contract])" target="_blank">{{ __('Print or save as PDF') }}</flux:button>
        @endif
    </div>

    @if ($contract)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700">
            {!! $contract->body_html !!}
        </div>
    @endif
</section>
