<?php

use App\Actions\Transfers\CompleteTransfer;
use App\Actions\Transfers\ReviewTransfer;
use App\Enums\TransferStatus;
use App\Models\Team;
use App\Models\TransferRequest;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Transfers')] class extends Component
{
    /**
     * The request open in the detail modal, kept in the address so a
     * notification can link straight to it.
     */
    #[Url(as: 'transfer')]
    public ?int $selectedId = null;

    public bool $showDetailModal = false;

    public string $moveDate = '';

    public bool $rejecting = false;

    public bool $cancelling = false;

    public string $decisionReason = '';

    public bool $proceedAnyway = false;

    public string $overrideReason = '';

    /**
     * Open the request named in the address, if it is one of this team's.
     */
    public function mount(): void
    {
        if ($this->selectedId && $this->selected) {
            $this->openModal();
        } else {
            $this->selectedId = null;
        }
    }

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canReview(): bool
    {
        return Auth::user()->canManageListingsOn($this->team);
    }

    /**
     * @return EloquentCollection<int, TransferRequest>
     */
    #[Computed]
    public function transfers(): EloquentCollection
    {
        return TransferRequest::query()
            ->where('team_id', $this->team->id)
            ->with(['tenant', 'fromUnit.property', 'toUnit.property'])
            ->latest()
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function selected(): ?TransferRequest
    {
        if (! $this->selectedId) {
            return null;
        }

        return TransferRequest::query()
            ->where('team_id', $this->team->id)
            ->with(['tenant', 'lease.currentRent', 'reviewer', 'fromUnit.property', 'toUnit.property'])
            ->find($this->selectedId);
    }

    /**
     * The tenant's rent now, and their share in the new unit as one more
     * tenant there.
     *
     * @return array{current: float, new: float}|null
     */
    #[Computed]
    public function rentChange(): ?array
    {
        $transfer = $this->selected;

        if (! $transfer) {
            return null;
        }

        return [
            'current' => $transfer->lease->currentRent ? (float) $transfer->lease->currentRent->amount : $transfer->fromUnit->rentSharePerTenant(),
            'new' => $transfer->toUnit->rentShareForNewcomer(),
        ];
    }

    #[Computed]
    public function moveOutCheckRecorded(): bool
    {
        return $this->selected !== null && app(CompleteTransfer::class)->moveOutCheckFor($this->selected) !== null;
    }

    #[Computed]
    public function moveInCheckClean(): bool
    {
        $check = $this->selected?->toUnit->pendingMoveInCheck();

        return $check !== null && $check->blockingItemCount() === 0;
    }

    public function open(int $transferId): void
    {
        $this->selectedId = $this->teamTransfer($transferId)->id;
        $this->openModal();
    }

    public function closeDetail(): void
    {
        $this->showDetailModal = false;
        $this->selectedId = null;
        $this->resetForm();
    }

    public function approve(ReviewTransfer $reviewTransfer): void
    {
        $transfer = $this->reviewable();

        $this->validate(['moveDate' => ['required', 'date', 'after_or_equal:today']], attributes: ['moveDate' => __('move date')]);

        $reviewTransfer->approve($transfer, Auth::user(), CarbonImmutable::parse($this->moveDate));

        $this->afterDecision(__('Transfer approved. The new unit is held until the move.'));
    }

    public function reject(ReviewTransfer $reviewTransfer): void
    {
        $reviewTransfer->reject($this->reviewable(), Auth::user(), $this->decisionReason);

        $this->afterDecision(__('Transfer rejected. The tenant was told why.'));
    }

    public function cancel(ReviewTransfer $reviewTransfer): void
    {
        $reviewTransfer->cancel($this->reviewable(), Auth::user(), $this->decisionReason);

        $this->afterDecision(__('Transfer cancelled and the held slot released.'));
    }

    public function complete(CompleteTransfer $completeTransfer): void
    {
        $transfer = $this->reviewable();

        if (! $this->moveInCheckClean) {
            if (! $this->proceedAnyway) {
                $this->addError('transfer', __('Record a clean move-in check of the new unit, or tick "Proceed anyway" and give a reason.'));

                return;
            }

            $this->validate(['overrideReason' => ['required', 'string', 'min:10', 'max:500']], attributes: ['overrideReason' => __('reason')]);
        }

        $completeTransfer->handle($transfer, Auth::user(), $this->moveInCheckClean ? null : $this->overrideReason);

        $this->afterDecision(__('Move completed. The tenant\'s new contract is ready for them to agree to.'));
    }

    protected function openModal(): void
    {
        $this->resetForm();
        $preferred = $this->selected?->preferred_date;
        $this->moveDate = $this->selected?->move_date?->toDateString()
            ?? ($preferred !== null && $preferred->isFuture() ? $preferred->toDateString() : today()->toDateString());
        $this->showDetailModal = true;
    }

    protected function afterDecision(string $message): void
    {
        $this->closeDetail();
        unset($this->transfers, $this->selected);

        Flux::toast(variant: 'success', text: $message);
    }

    protected function resetForm(): void
    {
        $this->reset('moveDate', 'rejecting', 'cancelling', 'decisionReason', 'proceedAnyway', 'overrideReason');
        $this->resetErrorBag();
        unset($this->selected, $this->rentChange, $this->moveOutCheckRecorded, $this->moveInCheckClean);
    }

    /**
     * The open request, after checking the user may decide it.
     */
    protected function reviewable(): TransferRequest
    {
        abort_unless($this->selectedId, 404);

        $transfer = $this->teamTransfer($this->selectedId);

        Gate::authorize('review', $transfer);

        return $transfer;
    }

    protected function teamTransfer(int $transferId): TransferRequest
    {
        return TransferRequest::query()->where('team_id', $this->team->id)->findOrFail($transferId);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Transfers') }}</flux:heading>
        <flux:subheading>{{ __('Tenants asking to move to another of your units.') }}</flux:subheading>
    </div>

    @unless ($this->canReview)
        <flux:text class="text-zinc-500">{{ __('You can view transfers and record the checks. Ask your landlord or a manager to approve or complete them.') }}</flux:text>
    @endunless

    @php
        $groups = [
            [__('Waiting for your decision'), $this->transfers->where('status', TransferStatus::Pending), __('No requests waiting.')],
            [__('Approved: moves coming up'), $this->transfers->where('status', TransferStatus::Approved), __('No moves coming up.')],
            [__('History'), $this->transfers->whereNotIn('status', [TransferStatus::Pending, TransferStatus::Approved]), __('Nothing finished yet.')],
        ];
    @endphp

    @foreach ($groups as [$heading, $items, $emptyText])
        <div class="space-y-2" wire:key="group-{{ $loop->index }}">
            <flux:heading size="sm">{{ $heading }} ({{ $items->count() }})</flux:heading>

            @forelse ($items as $transfer)
                <button
                    type="button"
                    wire:key="transfer-{{ $transfer->id }}"
                    wire:click="open({{ $transfer->id }})"
                    class="flex w-full items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 text-left transition hover:border-zinc-300 dark:border-zinc-700"
                >
                    <div>
                        <flux:heading size="sm">{{ $transfer->tenant->name }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ __('Unit :from to Unit :to', ['from' => $transfer->fromUnit->unit_number, 'to' => $transfer->toUnit->unit_number]) }}
                            &middot; {{ $transfer->toUnit->property->name }}
                        </flux:text>
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($transfer->status === TransferStatus::Approved)
                            @if ($transfer->isDueToMove())
                                <flux:badge size="sm" color="amber">{{ __('Move is due') }}</flux:badge>
                            @else
                                <flux:text class="text-sm">{{ __('Moves :date', ['date' => $transfer->move_date?->format('M j, Y')]) }}</flux:text>
                            @endif
                        @elseif ($transfer->status === TransferStatus::Pending)
                            <flux:text class="text-sm text-zinc-500">{{ __('Wants :date', ['date' => $transfer->preferred_date->format('M j, Y')]) }}</flux:text>
                        @else
                            <flux:badge size="sm" :color="$transfer->status->color()">{{ $transfer->status->label() }}</flux:badge>
                        @endif
                    </div>
                </button>
            @empty
                <flux:text class="text-zinc-500">{{ $emptyText }}</flux:text>
            @endforelse
        </div>
    @endforeach

    <flux:modal name="transfer-detail-modal" class="max-w-2xl md:min-w-2xl" @close="closeDetail" wire:model="showDetailModal">
        @if ($transfer = $this->selected)
            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg">{{ $transfer->tenant->name }}</flux:heading>
                    <flux:badge size="sm" :color="$transfer->status->color()">{{ $transfer->status->label() }}</flux:badge>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-500">{{ __('From') }}</dt>
                        <dd>{{ $transfer->fromUnit->property->name }} &middot; {{ __('Unit :number', ['number' => $transfer->fromUnit->unit_number]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('To') }}</dt>
                        <dd>{{ $transfer->toUnit->property->name }} &middot; {{ __('Unit :number', ['number' => $transfer->toUnit->unit_number]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Wants to move on') }}</dt>
                        <dd>{{ $transfer->preferred_date->format('M j, Y') }}</dd>
                    </div>
                    @if ($transfer->move_date)
                        <div>
                            <dt class="text-zinc-500">{{ __('Move date') }}</dt>
                            <dd>{{ $transfer->move_date->format('M j, Y') }}</dd>
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <dt class="text-zinc-500">{{ __('Reason') }}</dt>
                        <dd class="whitespace-pre-line">{{ $transfer->reason }}</dd>
                    </div>
                </dl>

                @if ($transfer->status->isOpen() && ($change = $this->rentChange))
                    <p class="rounded-lg bg-sand/40 p-3 text-sm text-zinc-800">
                        {{ __('Their rent would change from :current to :new per month.', [
                            'current' => '₱'.number_format($change['current'], 2),
                            'new' => '₱'.number_format($change['new'], 2),
                        ]) }}
                    </p>
                @endif

                @if ($transfer->decision_reason)
                    <flux:callout icon="chat-bubble-left">
                        <flux:callout.text>{{ __('Reason given: :reason', ['reason' => $transfer->decision_reason]) }}</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:error name="transfer" />
                <flux:error name="unit_id" />

                @if ($this->canReview && $transfer->status === TransferStatus::Pending)
                    @if ($rejecting)
                        <div class="space-y-3">
                            <flux:textarea wire:model="decisionReason" :label="__('Reason (sent to the tenant)')" rows="3" />
                            <flux:error name="decisionReason" />
                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="$set('rejecting', false)">{{ __('Back') }}</flux:button>
                                <flux:button variant="danger" wire:click="reject">{{ __('Reject request') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <flux:input wire:model="moveDate" type="date" :min="today()->toDateString()" :label="__('Move date')" class="max-w-48" />
                            <div class="flex gap-2">
                                <flux:button wire:click="$set('rejecting', true)">{{ __('Reject') }}</flux:button>
                                <flux:button variant="primary" wire:click="approve">{{ __('Approve') }}</flux:button>
                            </div>
                        </div>
                        <flux:text class="text-xs text-zinc-500">{{ __('Approving holds a slot in the new unit until the move.') }}</flux:text>
                    @endif
                @endif

                @if ($transfer->status === TransferStatus::Approved)
                    <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Before the move') }}</flux:heading>

                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="flex items-center gap-2">
                                @if ($this->moveOutCheckRecorded)
                                    <flux:icon.check-circle class="size-5 text-lime-600" />
                                @else
                                    <flux:icon.clock class="size-5 text-amber-600" />
                                @endif
                                {{ __('Move-out check of Unit :number', ['number' => $transfer->fromUnit->unit_number]) }}
                            </span>
                            <flux:link :href="route('units.inventory', ['unit' => $transfer->from_unit_id])" wire:navigate>{{ $this->moveOutCheckRecorded ? __('View') : __('Record it') }}</flux:link>
                        </div>

                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="flex items-center gap-2">
                                @if ($this->moveInCheckClean)
                                    <flux:icon.check-circle class="size-5 text-lime-600" />
                                @else
                                    <flux:icon.clock class="size-5 text-amber-600" />
                                @endif
                                {{ __('Move-in check of Unit :number', ['number' => $transfer->toUnit->unit_number]) }}
                            </span>
                            <flux:link :href="route('units.inventory', ['unit' => $transfer->to_unit_id])" wire:navigate>{{ $this->moveInCheckClean ? __('View') : __('Record it') }}</flux:link>
                        </div>

                        @if ($this->canReview)
                            @unless ($this->moveInCheckClean)
                                <flux:checkbox wire:model.live="proceedAnyway" :label="__('Proceed anyway')" :description="__('Move the tenant without a clean move-in check. Your reason is kept on the new lease.')" />
                                @if ($proceedAnyway)
                                    <flux:textarea wire:model="overrideReason" :label="__('Reason')" rows="2" maxlength="500" />
                                    <flux:error name="overrideReason" />
                                @endif
                            @endunless

                            <div class="flex justify-end">
                                <flux:button variant="primary" wire:click="complete" :disabled="! $transfer->isDueToMove() || ! $this->moveOutCheckRecorded">
                                    {{ __('Complete move') }}
                                </flux:button>
                            </div>
                            @unless ($transfer->isDueToMove())
                                <flux:text class="text-right text-xs text-zinc-500">{{ __('You can complete the move from :date.', ['date' => $transfer->move_date?->format('M j, Y')]) }}</flux:text>
                            @endunless
                        @endif
                    </div>

                    @if ($this->canReview)
                        @if ($cancelling)
                            <div class="space-y-3">
                                <flux:textarea wire:model="decisionReason" :label="__('Reason for cancelling (sent to the tenant)')" rows="3" />
                                <flux:error name="decisionReason" />
                                <div class="flex justify-end gap-2">
                                    <flux:button wire:click="$set('cancelling', false)">{{ __('Back') }}</flux:button>
                                    <flux:button variant="danger" wire:click="cancel">{{ __('Cancel transfer') }}</flux:button>
                                </div>
                            </div>
                        @else
                            <div class="flex justify-end">
                                <flux:button variant="ghost" size="sm" wire:click="$set('cancelling', true)">{{ __('Cancel transfer') }}</flux:button>
                            </div>
                        @endif
                    @endif
                @endif

                @if ($transfer->status === TransferStatus::Completed && $transfer->new_lease_id)
                    <div class="flex justify-end">
                        <flux:button size="sm" icon="document-text" :href="route('leases.contract', ['lease' => $transfer->new_lease_id])" wire:navigate>{{ __('New contract') }}</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</section>
