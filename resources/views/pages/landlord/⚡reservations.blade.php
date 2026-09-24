<?php

use App\Actions\Reservations\ApproveReservation;
use App\Actions\Reservations\RejectReservation;
use App\Actions\Reservations\ResendLoginDetails;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reservations')] class extends Component
{
    public ?int $selectedId = null;

    public bool $showDetailModal = false;

    public bool $downpaymentConfirmed = false;

    public bool $rejecting = false;

    public string $rejectReason = '';

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
     * @return Collection<int, Reservation>
     */
    #[Computed]
    public function pending(): Collection
    {
        return Reservation::query()
            ->where('team_id', $this->team->id)
            ->pending()
            ->with(['unit.property'])
            ->oldest()
            ->get();
    }

    /**
     * @return Collection<int, Reservation>
     */
    #[Computed]
    public function history(): Collection
    {
        return Reservation::query()
            ->where('team_id', $this->team->id)
            ->where('status', '!=', ReservationStatus::Pending->value)
            ->with(['unit.property'])
            ->latest('reviewed_at')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function selected(): ?Reservation
    {
        if (! $this->selectedId) {
            return null;
        }

        return Reservation::query()
            ->where('team_id', $this->team->id)
            ->with(['unit.property', 'paymentChannel', 'tenant', 'reviewer'])
            ->find($this->selectedId);
    }

    public function open(int $reservationId): void
    {
        $this->resetForm();
        $this->selectedId = $this->teamReservation($reservationId)->id;
        $this->showDetailModal = true;
    }

    public function closeDetail(): void
    {
        $this->showDetailModal = false;
        $this->resetForm();
        $this->selectedId = null;
    }

    public function startRejecting(): void
    {
        $this->rejecting = true;
        $this->resetErrorBag();
    }

    public function approve(ApproveReservation $approve): void
    {
        $reservation = $this->reviewable();

        $approve->handle($reservation, Auth::user(), $this->downpaymentConfirmed);

        $this->afterReview(__('Reservation approved. Login details were emailed to the applicant.'));
    }

    public function reject(RejectReservation $reject): void
    {
        $reservation = $this->reviewable();

        $reject->handle($reservation, Auth::user(), $this->rejectReason);

        $this->afterReview(__('Reservation rejected. The applicant was emailed.'));
    }

    public function resendLoginDetails(ResendLoginDetails $resend): void
    {
        $resend->handle($this->reviewable());

        Flux::toast(variant: 'success', text: __('New login details were emailed to the applicant.'));
    }

    protected function afterReview(string $message): void
    {
        $this->closeDetail();
        unset($this->pending, $this->history, $this->selected);

        Flux::toast(variant: 'success', text: $message);
    }

    protected function resetForm(): void
    {
        $this->reset('downpaymentConfirmed', 'rejecting', 'rejectReason');
        $this->resetErrorBag();
    }

    /**
     * The open reservation, after checking the user may review it.
     */
    protected function reviewable(): Reservation
    {
        abort_unless($this->selectedId, 404);

        $reservation = $this->teamReservation($this->selectedId);

        Gate::authorize('review', $reservation);

        return $reservation;
    }

    /**
     * Find a reservation on the current team, or fail with a 404.
     */
    protected function teamReservation(int $reservationId): Reservation
    {
        $reservation = Reservation::query()
            ->where('team_id', $this->team->id)
            ->findOrFail($reservationId);

        Gate::authorize('view', $reservation);

        return $reservation;
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Reservations') }}</flux:heading>
        <flux:subheading>{{ __('Applications from people who found a unit on the public pages.') }}</flux:subheading>
    </div>

    @unless ($this->canReview)
        <flux:text class="text-zinc-400">{{ __('You can view reservations. Ask your landlord or a manager to approve or reject them.') }}</flux:text>
    @endunless

    <div class="space-y-2">
        <flux:heading size="sm">{{ __('Pending') }} ({{ $this->pending->count() }})</flux:heading>

        @forelse ($this->pending as $reservation)
            <button
                type="button"
                wire:key="pending-{{ $reservation->id }}"
                wire:click="open({{ $reservation->id }})"
                class="flex w-full items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 text-left transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
            >
                <div>
                    <flux:heading size="sm">{{ $reservation->fullName() }}</flux:heading>
                    <flux:text class="text-zinc-400">
                        {{ $reservation->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $reservation->unit->unit_number]) }}
                        &middot; {{ $reservation->created_at?->diffForHumans() }}
                    </flux:text>
                </div>
                <div class="text-right">
                    <flux:text>&#8369;{{ number_format((float) $reservation->downpayment_amount, 2) }}</flux:text>
                    <flux:text class="text-xs text-zinc-400">{{ $reservation->code }}</flux:text>
                </div>
            </button>
        @empty
            <flux:text class="text-zinc-400">{{ __('No reservations waiting for review.') }}</flux:text>
        @endforelse
    </div>

    <div class="space-y-2">
        <flux:heading size="sm">{{ __('History') }}</flux:heading>

        @forelse ($this->history as $reservation)
            <button
                type="button"
                wire:key="history-{{ $reservation->id }}"
                wire:click="open({{ $reservation->id }})"
                class="flex w-full items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 text-left transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
            >
                <div>
                    <flux:heading size="sm">{{ $reservation->fullName() }}</flux:heading>
                    <flux:text class="text-zinc-400">
                        {{ $reservation->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $reservation->unit->unit_number]) }}
                    </flux:text>
                </div>
                <flux:badge size="sm" :color="$reservation->status->color()">{{ $reservation->status->label() }}</flux:badge>
            </button>
        @empty
            <flux:text class="text-zinc-400">{{ __('Nothing reviewed yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="reservation-detail-modal" class="max-w-2xl md:min-w-2xl" @close="closeDetail" wire:model="showDetailModal">
        @if ($this->selected)
            @php($reservation = $this->selected)

            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="lg">{{ $reservation->fullName() }}</flux:heading>
                    <flux:badge size="sm" :color="$reservation->status->color()">{{ $reservation->status->label() }}</flux:badge>
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-400">{{ __('Unit') }}</dt>
                        <dd>{{ $reservation->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $reservation->unit->unit_number]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">{{ __('Reference code') }}</dt>
                        <dd>{{ $reservation->code }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">{{ __('Email') }}</dt>
                        <dd>{{ $reservation->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">{{ __('Requested username') }}</dt>
                        <dd>{{ $reservation->desired_username }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">{{ __('Age') }}</dt>
                        <dd>{{ $reservation->age }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-400">{{ __('Address') }}</dt>
                        <dd>{{ $reservation->address }}</dd>
                    </div>
                </dl>

                <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Downpayment') }}</flux:heading>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-zinc-400">{{ __('Amount') }}</dt>
                            <dd>&#8369;{{ number_format((float) $reservation->downpayment_amount, 2) }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-400">{{ __('Paid to') }}</dt>
                            <dd>{{ $reservation->paymentChannel ? $reservation->paymentChannel->method->label().' · '.$reservation->paymentChannel->account_name : $reservation->downpayment_method->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-400">{{ __('Reference number') }}</dt>
                            <dd>{{ $reservation->downpayment_reference }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-400">{{ __('Method') }}</dt>
                            <dd>{{ $reservation->downpayment_method->label() }}</dd>
                        </div>
                    </dl>

                    @if ($reservation->hasFiles())
                        <div class="grid gap-3 sm:grid-cols-2">
                            <a href="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'proof']) }}" target="_blank" rel="noopener">
                                <img
                                    src="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'proof']) }}"
                                    alt="{{ __('Proof of payment') }}"
                                    class="max-h-72 w-full rounded-md border border-zinc-200 object-contain dark:border-zinc-700"
                                >
                                <flux:text class="mt-1 text-xs text-zinc-400">{{ __('Proof of payment') }}</flux:text>
                            </a>
                            <a href="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'id']) }}" target="_blank" rel="noopener">
                                <img
                                    src="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'id']) }}"
                                    alt="{{ __('Valid ID') }}"
                                    class="max-h-72 w-full rounded-md border border-zinc-200 object-contain dark:border-zinc-700"
                                >
                                <flux:text class="mt-1 text-xs text-zinc-400">{{ __('Valid ID') }}</flux:text>
                            </a>
                        </div>
                    @else
                        <flux:text class="text-zinc-400">{{ __('The ID and payment proof were deleted after the retention period.') }}</flux:text>
                    @endif
                </div>

                @if ($reservation->rejection_reason)
                    <flux:callout variant="danger" icon="x-circle">
                        <flux:callout.heading>{{ __('Rejected') }}</flux:callout.heading>
                        <flux:callout.text>{{ $reservation->rejection_reason }}</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:error name="reservation" />

                @if ($this->canReview && $reservation->status === ReservationStatus::Pending)
                    @if ($rejecting)
                        <div class="space-y-3">
                            <flux:callout variant="warning" icon="exclamation-triangle">
                                <flux:callout.text>
                                    {{ __('If this applicant already sent a downpayment, the refund is handled outside Occuplace. Contact them directly.') }}
                                </flux:callout.text>
                            </flux:callout>

                            <flux:textarea wire:model="rejectReason" :label="__('Reason (sent to the applicant)')" rows="3" required />

                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="$set('rejecting', false)">{{ __('Back') }}</flux:button>
                                <flux:button variant="danger" wire:click="reject">{{ __('Reject reservation') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <div class="space-y-3">
                            <flux:checkbox
                                wire:model="downpaymentConfirmed"
                                :label="__('I confirmed this downpayment arrived in my account')"
                            />

                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="startRejecting">{{ __('Reject') }}</flux:button>
                                <flux:button variant="primary" wire:click="approve">{{ __('Approve') }}</flux:button>
                            </div>
                        </div>
                    @endif
                @endif

                @if ($this->canReview && $reservation->status === ReservationStatus::Approved && $reservation->tenant?->must_change_password)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-zinc-400">
                            {{ __('The applicant has not logged in yet. Send a fresh temporary password if the email never arrived or expired.') }}
                        </flux:text>
                        <flux:button wire:click="resendLoginDetails">{{ __('Resend login details') }}</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</section>
