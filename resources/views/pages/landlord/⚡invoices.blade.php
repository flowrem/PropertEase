<?php

use App\Actions\Invoices\RecordInvoicePayment;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TeamRole;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Team;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoices')] class extends Component
{
    public string $search = '';

    public bool $showTenantModal = false;

    public ?int $viewingTenantId = null;

    public bool $showPaymentModal = false;

    public ?int $recordingInvoiceId = null;

    public string $amount = '';

    public string $method = '';

    public string $reference_number = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Every tenant on this team who has at least one invoice, each with
     * their leases (including past ones) and every invoice eager loaded,
     * filtered by name, email, property, or unit number when searching,
     * and sorted so whoever owes the most shows up first.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function tenants(): Collection
    {
        $tenants = $this->team->members()
            ->wherePivot('role', TeamRole::Tenant->value)
            ->with(['leases' => fn ($leases) => $leases
                ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
                ->with([
                    'unit.property',
                    'invoices' => fn ($invoices) => $invoices->with('payments')->orderByDesc('due_date'),
                ]),
            ])
            ->get()
            ->filter(fn (User $tenant) => $this->invoicesFor($tenant)->isNotEmpty());

        $search = trim($this->search);

        if ($search !== '') {
            $tenants = $tenants->filter(function (User $tenant) use ($search) {
                $lease = $tenant->leases->first();

                return Str::contains($tenant->name, $search, ignoreCase: true)
                    || Str::contains($tenant->email, $search, ignoreCase: true)
                    || ($lease && Str::contains($lease->unit->property->name, $search, ignoreCase: true))
                    || ($lease && Str::contains($lease->unit->unit_number, $search, ignoreCase: true));
            });
        }

        return $tenants
            ->sortByDesc(fn (User $tenant) => $this->outstandingBalance($tenant))
            ->values();
    }

    /**
     * The tenant currently open in the invoice modal.
     */
    #[Computed]
    public function viewingTenant(): ?User
    {
        if (! $this->viewingTenantId) {
            return null;
        }

        return $this->tenants->firstWhere('id', $this->viewingTenantId);
    }

    /**
     * Every invoice this tenant has ever been billed, across every lease
     * they've held on this team, newest due date first.
     *
     * @return Collection<int, Invoice>
     */
    public function invoicesFor(User $tenant): Collection
    {
        return $tenant->leases
            ->flatMap(fn (Lease $lease) => $lease->invoices->map(function (Invoice $invoice) use ($lease) {
                $invoice->setRelation('lease', $lease);

                return $invoice;
            }))
            ->sortByDesc('due_date')
            ->values();
    }

    /**
     * How much this tenant currently owes across every invoice that isn't
     * fully paid.
     */
    public function outstandingBalance(User $tenant): float
    {
        return round($this->invoicesFor($tenant)
            ->reject(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Paid)
            ->sum(fn (Invoice $invoice) => $invoice->balanceDue()), 2);
    }

    public function viewTenant(int $tenantId): void
    {
        $this->viewingTenantId = $tenantId;
        $this->showTenantModal = true;
    }

    public function closeTenantModal(): void
    {
        $this->showTenantModal = false;
        $this->viewingTenantId = null;
    }

    public function confirmRecordPayment(int $invoiceId): void
    {
        $invoice = $this->teamInvoice($invoiceId);

        $this->recordingInvoiceId = $invoice->id;
        $this->amount = (string) $invoice->balanceDue();
        $this->method = '';
        $this->reference_number = '';
        $this->showTenantModal = false;
        $this->showPaymentModal = true;
    }

    public function recordPayment(RecordInvoicePayment $recordInvoicePayment): void
    {
        $invoice = $this->teamInvoice($this->recordingInvoiceId);

        $validated = $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:'.$invoice->balanceDue()],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference_number' => ['nullable', 'string', 'max:255'],
        ]);

        $recordInvoicePayment->handle(
            $invoice,
            (float) $validated['amount'],
            PaymentMethod::from($validated['method']),
            $validated['reference_number'] ?: null,
        );

        $this->showPaymentModal = false;
        $this->recordingInvoiceId = null;
        $this->reset('amount', 'method', 'reference_number');
        $this->showTenantModal = true;

        unset($this->tenants);

        Flux::toast(variant: 'success', text: __('Payment recorded.'));
    }

    public function closePaymentModal(): void
    {
        $this->showPaymentModal = false;
        $this->recordingInvoiceId = null;
        $this->reset('amount', 'method', 'reference_number');
    }

    /**
     * Find an invoice belonging to the current team, or fail with a 404.
     */
    protected function teamInvoice(int $invoiceId): Invoice
    {
        return Invoice::with('payments')
            ->whereHas('lease.unit.property', fn ($query) => $query->where('team_id', $this->team->id))
            ->findOrFail($invoiceId);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Invoices') }}</flux:heading>
        <flux:subheading>{{ __('Track who owes rent and record payments for :team.', ['team' => $this->team->name]) }}</flux:subheading>
    </div>

    <flux:input
        wire:model.live.debounce.300ms="search"
        :label="__('Search')"
        :placeholder="__('Search by name, email, property, or unit')"
        clearable
    />

    @forelse ($this->tenants as $tenant)
        @php($balance = $this->outstandingBalance($tenant))
        @php($invoices = $this->invoicesFor($tenant))
        @php($hasOverdue = $invoices->contains(fn ($invoice) => $invoice->isPastDue()))

        <div
            wire:key="ledger-tenant-{{ $tenant->id }}"
            wire:click="viewTenant({{ $tenant->id }})"
            class="flex cursor-pointer items-center justify-between rounded-lg border border-zinc-200 p-4 transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
        >
            <div>
                <flux:heading size="sm">{{ $tenant->name }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $tenant->email }}</flux:text>
            </div>

            <div class="flex flex-col items-end gap-1">
                @if ($balance > 0)
                    <flux:badge :color="$hasOverdue ? 'red' : 'amber'">
                        &#8369;{{ number_format($balance, 2) }} {{ __('due') }}
                    </flux:badge>
                @else
                    <flux:badge color="lime">{{ __('Settled') }}</flux:badge>
                @endif

                <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                    {{ $invoices->count() }} {{ Str::plural('invoice', $invoices->count()) }}
                </flux:text>
            </div>
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('No invoices yet') }}</flux:heading>
            <flux:subheading>{{ __('Invoices generated for your tenants will show up here.') }}</flux:subheading>
        </div>
    @endforelse

    <flux:modal name="tenant-invoices-modal" class="max-w-2xl" @close="closeTenantModal" wire:model="showTenantModal">
        @if ($this->viewingTenant)
            @php($tenant = $this->viewingTenant)
            @php($invoices = $this->invoicesFor($tenant))
            @php($outstanding = $invoices->reject(fn ($invoice) => $invoice->status === InvoiceStatus::Paid))
            @php($history = $invoices->filter(fn ($invoice) => $invoice->status === InvoiceStatus::Paid))

            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $tenant->name }}</flux:heading>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $tenant->email }}</flux:text>
                </div>

                <div>
                    <flux:heading size="sm" class="mb-2">{{ __('Outstanding') }}</flux:heading>

                    <div class="flex flex-col gap-2">
                        @forelse ($outstanding as $invoice)
                            <div wire:key="outstanding-{{ $invoice->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <div>
                                    <flux:text class="font-medium text-zinc-900 dark:text-white">
                                        {{ $invoice->lease->unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $invoice->lease->unit->unit_number]) }}
                                    </flux:text>
                                    <flux:text class="block text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $invoice->billing_start->format('M j') }} &ndash; {{ $invoice->billing_end->format('M j, Y') }}
                                        &middot; {{ __('due') }} {{ $invoice->due_date->format('M j, Y') }}
                                    </flux:text>
                                </div>

                                <div class="flex items-center gap-3">
                                    <div class="text-right">
                                        <flux:text class="block font-medium text-zinc-900 dark:text-white">
                                            &#8369;{{ number_format($invoice->balanceDue(), 2) }}
                                        </flux:text>
                                        <flux:badge :color="$invoice->isPastDue() ? 'red' : $invoice->status->color()" size="sm">
                                            {{ $invoice->isPastDue() ? __('Overdue') : $invoice->status->label() }}
                                        </flux:badge>
                                    </div>

                                    <flux:button size="sm" variant="primary" wire:click="confirmRecordPayment({{ $invoice->id }})">
                                        {{ __('Record payment') }}
                                    </flux:button>
                                </div>
                            </div>
                        @empty
                            <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Nothing outstanding.') }}</flux:text>
                        @endforelse
                    </div>
                </div>

                <div>
                    <flux:heading size="sm" class="mb-2">{{ __('History') }}</flux:heading>

                    <div class="flex flex-col gap-2">
                        @forelse ($history as $invoice)
                            <div wire:key="history-{{ $invoice->id }}" class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
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

                <div class="flex justify-end">
                    <flux:button variant="filled" wire:click="closeTenantModal">{{ __('Close') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal name="record-payment-modal" class="max-w-md md:min-w-md" @close="closePaymentModal" wire:model="showPaymentModal">
        <form wire:submit="recordPayment" class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Record payment') }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('Log what was actually received for this invoice.') }}</flux:text>
            </div>

            <flux:input wire:model="amount" type="number" step="0.01" min="0.01" :label="__('Amount received (₱)')" required />

            <flux:select wire:model="method" :label="__('Method')" required>
                <flux:select.option value="">{{ __('Select a method') }}</flux:select.option>
                @foreach (PaymentMethod::cases() as $paymentMethod)
                    <flux:select.option value="{{ $paymentMethod->value }}">{{ $paymentMethod->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="reference_number" :label="__('Reference number')" :placeholder="__('Optional')" />

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="closePaymentModal">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Record payment') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
