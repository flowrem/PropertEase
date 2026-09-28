<?php

use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Contract terms')] class extends Component
{
    public string $advance_months = '';

    public string $deposit_months = '';

    public string $minimum_stay_months_short = '';

    public string $minimum_stay_months_long = '';

    public string $notice_days = '';

    public string $late_fee = '';

    public string $house_rules = '';

    public string $additional_terms = '';

    public function mount(): void
    {
        $terms = $this->team->contractTerms();

        $this->advance_months = (string) $terms->advance_months;
        $this->deposit_months = (string) $terms->deposit_months;
        $this->minimum_stay_months_short = (string) $terms->minimum_stay_months_short;
        $this->minimum_stay_months_long = (string) $terms->minimum_stay_months_long;
        $this->notice_days = (string) $terms->notice_days;
        $this->late_fee = (string) $terms->late_fee;
        $this->house_rules = (string) $terms->house_rules;
        $this->additional_terms = (string) $terms->additional_terms;
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->canManageListingsOn($this->team);
    }

    /**
     * Save the terms new contracts are made from. Contracts already made
     * keep their own copy.
     */
    public function save(): void
    {
        abort_unless($this->canManage, 403);

        $limits = config('occuplace.contracts');
        $range = fn (string $field): array => ['required', 'integer', 'min:'.$limits[$field]['min'], 'max:'.$limits[$field]['max']];

        $validated = $this->validate([
            'advance_months' => $range('advance_months'),
            'deposit_months' => $range('deposit_months'),
            'minimum_stay_months_short' => $range('minimum_stay_months_short'),
            'minimum_stay_months_long' => [...$range('minimum_stay_months_long'), 'gte:minimum_stay_months_short'],
            'notice_days' => $range('notice_days'),
            'late_fee' => ['nullable', 'numeric', 'min:1', 'max:'.$limits['late_fee_max']],
            'house_rules' => ['nullable', 'string', 'max:'.$limits['text_max_length']],
            'additional_terms' => ['nullable', 'string', 'max:'.$limits['text_max_length']],
        ], [
            'minimum_stay_months_long.gte' => __('A long-term stay can\'t be shorter than a short-term one.'),
        ]);

        $this->team->contractTemplate()->updateOrCreate([], [
            ...$validated,
            'late_fee' => $validated['late_fee'] === '' ? null : $validated['late_fee'],
            'house_rules' => trim((string) $validated['house_rules']) ?: null,
            'additional_terms' => trim((string) $validated['additional_terms']) ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Contract terms saved. New contracts use them; existing ones keep their own terms.'));
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Contract terms') }}</flux:heading>
        <flux:subheading>{{ __('Each tenant\'s contract is made from these when they move in. Changing them later never changes a contract already made.') }}</flux:subheading>
    </div>

    @unless ($this->canManage)
        <flux:text class="text-zinc-500">{{ __('You can view these terms. Ask your landlord or a manager to change them.') }}</flux:text>
    @endunless

    <form wire:submit="save" class="flex flex-col gap-6">
        <fieldset @disabled(! $this->canManage) class="flex flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="advance_months" type="number" min="0" max="3" :label="__('Advance (months of rent)')" :description="__('Paid before moving in, used for the first months.')" />
                <flux:input wire:model="deposit_months" type="number" min="0" max="3" :label="__('Deposit (months of rent)')" :description="__('Held for damage or unpaid bills, returned at move-out.')" />
                <flux:input wire:model="minimum_stay_months_short" type="number" min="1" max="12" :label="__('Minimum stay, short-term (months)')" />
                <flux:input wire:model="minimum_stay_months_long" type="number" min="1" max="36" :label="__('Minimum stay, long-term (months)')" />
                <flux:input wire:model="notice_days" type="number" min="0" max="90" :label="__('Notice before moving out (days)')" />
                <flux:input wire:model="late_fee" type="number" min="1" step="0.01" :label="__('Late payment fee (₱, optional)')" :description="__('Leave empty for no late fee.')" />
            </div>

            <flux:textarea wire:model="house_rules" rows="6" maxlength="5000" :label="__('House rules')" :placeholder="__('Quiet hours from 10 PM. No pets. Visitors leave by 9 PM.')" />
            <flux:textarea wire:model="additional_terms" rows="5" maxlength="5000" :label="__('Other terms (optional)')" />
        </fieldset>

        @if ($this->canManage)
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Save terms') }}</flux:button>
            </div>
        @endif
    </form>
</section>
