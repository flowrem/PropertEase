<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Billing')] class extends Component
{
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Every invoice this tenant has ever been billed on this team, across
     * every lease they've held (including units they've since moved out
     * of), newest due date first.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return Auth::user()->leases()
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with([
                'unit.property',
                'invoices' => fn ($invoices) => $invoices->with('payments')->orderByDesc('due_date'),
            ])
            ->get()
            ->flatMap(fn (Lease $lease) => $lease->invoices->map(function (Invoice $invoice) use ($lease) {
                $invoice->setRelation('lease', $lease);

                return $invoice;
            }))
            ->sortByDesc('due_date')
            ->values();
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function outstandingInvoices(): Collection
    {
        return $this->invoices
            ->reject(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Paid)
            ->sortBy('due_date')
            ->values();
    }

    /**
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function paidInvoices(): Collection
    {
        return $this->invoices
            ->filter(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Paid)
            ->values();
    }

    #[Computed]
    public function outstandingBalance(): float
    {
        return round($this->outstandingInvoices->sum(fn (Invoice $invoice) => $invoice->balanceDue()), 2);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Billing') }}</flux:heading>
        <flux:subheading>{{ __('Your balance, due dates, and payment history.') }}</flux:subheading>
    </div>

    <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Current balance') }}</flux:text>
        <flux:heading size="xl">&#8369;{{ number_format($this->outstandingBalance, 2) }}</flux:heading>

        @php($nextDue = $this->outstandingInvoices->first())

        @if ($nextDue)
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ __(':amount due :date', [
                    'amount' => '₱'.number_format($nextDue->balanceDue(), 2),
                    'date' => $nextDue->due_date->format('M j, Y'),
                ]) }}

                @if ($nextDue->isPastDue())
                    <flux:badge color="red" size="sm">{{ __('Overdue') }}</flux:badge>
                @endif
            </flux:text>
        @else
            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Nothing due right now.') }}</flux:text>
        @endif
    </div>

    <div>
        <flux:heading size="sm" class="mb-2">{{ __('Outstanding') }}</flux:heading>

        <div class="flex flex-col gap-2">
            @forelse ($this->outstandingInvoices as $invoice)
                <div wire:key="outstanding-{{ $invoice->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div>
                        <flux:text class="font-medium text-zinc-900 dark:text-white">
                            {{ $invoice->lease->unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $invoice->lease->unit->unit_number]) }}
                        </flux:text>
                        <flux:text class="block text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $invoice->billing_start->format('M j') }} &ndash; {{ $invoice->billing_end->format('M j, Y') }}
                            &middot; {{ __('due') }} {{ $invoice->due_date->format('M j, Y') }}
                        </flux:text>
                    </div>

                    <div class="text-right">
                        <flux:text class="block font-medium text-zinc-900 dark:text-white">
                            &#8369;{{ number_format($invoice->balanceDue(), 2) }}
                        </flux:text>
                        <flux:badge :color="$invoice->isPastDue() ? 'red' : $invoice->status->color()" size="sm">
                            {{ $invoice->isPastDue() ? __('Overdue') : $invoice->status->label() }}
                        </flux:badge>
                    </div>
                </div>
            @empty
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Nothing due right now.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <div>
        <flux:heading size="sm" class="mb-2">{{ __('History') }}</flux:heading>

        <div class="flex flex-col gap-2">
            @forelse ($this->paidInvoices as $invoice)
                <div wire:key="history-{{ $invoice->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div>
                        <flux:text class="font-medium text-zinc-900 dark:text-white">
                            {{ $invoice->lease->unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $invoice->lease->unit->unit_number]) }}
                        </flux:text>
                        <flux:text class="block text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $invoice->billing_start->format('M j') }} &ndash; {{ $invoice->billing_end->format('M j, Y') }}
                        </flux:text>
                    </div>

                    <div class="text-right">
                        <flux:text class="block font-medium text-zinc-900 dark:text-white">
                            &#8369;{{ number_format((float) $invoice->total_amount, 2) }}
                        </flux:text>
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ __('Paid') }} {{ optional($invoice->payments->last())->paid_at?->format('M j, Y') }}
                        </flux:text>
                    </div>
                </div>
            @empty
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No payments yet.') }}</flux:text>
            @endforelse
        </div>
    </div>
</section>
