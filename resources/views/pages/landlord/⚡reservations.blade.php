<?php

use App\Actions\Reservations\AcceptReservation;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\ExtendReservation;
use App\Actions\Reservations\RejectReservation;
use App\Actions\Reservations\ResendLoginDetails;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Team;
use App\Rules\PhilippineMobileNumber;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Reservations')] class extends Component
{
    /**
     * The reservation open in the detail modal, kept in the address so a
     * notification can link straight to it.
     */
    #[Url(as: 'reservation')]
    public ?int $selectedId = null;

    public bool $showDetailModal = false;

    public bool $downpaymentConfirmed = false;

    public bool $rejecting = false;

    public string $rejectReason = '';

    public bool $cancelling = false;

    public string $cancelReason = '';

    public string $extendDays = '';

    /**
     * Open the reservation named in the address, if it is one of this team's.
     */
    public function mount(): void
    {
        $this->extendDays = (string) $this->team->reservation_hold_days;

        if ($this->selectedId && $this->selected) {
            $this->showDetailModal = true;
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
     * Reservations still in progress, grouped by what the landlord does next:
     * review, check a downpayment, wait for one, or move the tenant in.
     *
     * @return Collection<string, Collection<int, Reservation>>
     */
    #[Computed]
    public function active(): Collection
    {
        $reservations = Reservation::query()
            ->where('team_id', $this->team->id)
            ->whereIn('status', [ReservationStatus::Pending->value, ReservationStatus::Reserved->value, ReservationStatus::Confirmed->value])
            ->with(['unit.property'])
            ->oldest()
            ->get();

        return collect([
            'review' => $reservations->where('status', ReservationStatus::Pending)->values(),
            'check' => $reservations->filter(fn (Reservation $reservation): bool => $reservation->status === ReservationStatus::Reserved && $reservation->hasDownpaymentSent())->values(),
            'waiting' => $reservations->filter(fn (Reservation $reservation): bool => $reservation->status === ReservationStatus::Reserved && ! $reservation->hasDownpaymentSent())->values(),
            'moveIn' => $reservations->where('status', ReservationStatus::Confirmed)->values(),
        ]);
    }

    /**
     * @return Collection<int, Reservation>
     */
    #[Computed]
    public function history(): Collection
    {
        return Reservation::query()
            ->where('team_id', $this->team->id)
            ->whereIn('status', [ReservationStatus::Rejected->value, ReservationStatus::Cancelled->value, ReservationStatus::Expired->value, ReservationStatus::Fulfilled->value])
            ->with(['unit.property'])
            ->latest('updated_at')
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
            ->with(['unit.property', 'paymentChannel', 'tenant', 'reviewer', 'listing'])
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

    public function accept(AcceptReservation $accept): void
    {
        $accept->handle($this->reviewable(), Auth::user());

        $this->afterReview(__('Reservation accepted. The applicant was emailed the deadline to pay the downpayment.'));
    }

    public function confirm(ConfirmReservation $confirm): void
    {
        $confirm->handle($this->reviewable(), Auth::user(), $this->downpaymentConfirmed);

        $this->afterReview(__('Reservation confirmed. Login details were emailed to the tenant.'));
    }

    public function extend(ExtendReservation $extend): void
    {
        $this->validate(['extendDays' => ['required', 'integer']], attributes: ['extendDays' => __('days')]);

        $extend->handle($this->reviewable(), (int) $this->extendDays);

        $this->afterReview(__('Deadline extended. The applicant was emailed the new one.'));
    }

    public function reject(RejectReservation $reject): void
    {
        $reject->handle($this->reviewable(), Auth::user(), $this->rejectReason);

        $this->afterReview(__('Reservation rejected. The applicant was emailed.'));
    }

    public function startCancelling(): void
    {
        $this->cancelling = true;
        $this->resetErrorBag();
    }

    public function cancel(CancelReservation $cancel): void
    {
        $cancel->handle($this->reviewable(), $this->cancelReason);

        $this->afterReview(__('Reservation cancelled and the slot released.'));
    }

    public function resendLoginDetails(ResendLoginDetails $resend): void
    {
        $resend->handle($this->reviewable());

        Flux::toast(variant: 'success', text: __('New login details were emailed to the applicant.'));
    }

    protected function afterReview(string $message): void
    {
        $this->closeDetail();
        unset($this->active, $this->history, $this->selected);

        Flux::toast(variant: 'success', text: $message);
    }

    protected function resetForm(): void
    {
        $this->reset('downpaymentConfirmed', 'rejecting', 'rejectReason', 'cancelling', 'cancelReason');
        $this->extendDays = (string) $this->team->reservation_hold_days;
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
        <flux:subheading>
            {{ trans_choice('Applications from people who found a unit on the public pages. An accepted reservation holds the unit for :count day while they pay the downpayment.|Applications from people who found a unit on the public pages. An accepted reservation holds the unit for :count days while they pay the downpayment.', $this->team->reservation_hold_days) }}
        </flux:subheading>
    </div>

    @unless ($this->canReview)
        <flux:text class="text-zinc-500">{{ __('You can view reservations. Ask your landlord or a manager to accept, confirm or reject them.') }}</flux:text>
    @endunless

    @php
        $groups = [
            'review' => [__('Needs your review'), __('No reservations waiting for review.')],
            'check' => [__('Downpayment sent: check and confirm'), __('No downpayments to check.')],
            'waiting' => [__('Waiting for the downpayment'), __('Nobody is paying a downpayment right now.')],
            'moveIn' => [__('Confirmed: ready to move in'), __('Nobody waiting to move in.')],
        ];
    @endphp

    @foreach ($groups as $group => [$heading, $emptyText])
        <div class="space-y-2" wire:key="group-{{ $group }}">
            <flux:heading size="sm">{{ $heading }} ({{ $this->active[$group]->count() }})</flux:heading>

            @forelse ($this->active[$group] as $reservation)
                <button
                    type="button"
                    wire:key="reservation-{{ $reservation->id }}"
                    wire:click="open({{ $reservation->id }})"
                    class="flex w-full items-center justify-between gap-4 rounded-lg border border-zinc-200 p-4 text-left transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
                >
                    <div>
                        <flux:heading size="sm">{{ $reservation->fullName() }}</flux:heading>
                        <flux:text class="text-zinc-500">
                            {{ $reservation->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $reservation->unit->unit_number]) }}
                            &middot; {{ $reservation->code }}
                        </flux:text>
                    </div>
                    <div class="shrink-0 text-right">
                        @if ($group === 'waiting')
                            @if ($reservation->isOverdue())
                                <flux:badge size="sm" color="red">{{ __('Deadline passed') }}</flux:badge>
                            @else
                                <flux:text class="text-sm">{{ __('Due :deadline', ['deadline' => $reservation->deadlineForDisplay()]) }}</flux:text>
                            @endif
                        @elseif ($group === 'check')
                            <flux:text class="text-sm">&#8369;{{ number_format((float) $reservation->downpayment_amount, 2) }}</flux:text>
                        @else
                            <flux:text class="text-sm text-zinc-500">{{ $reservation->created_at?->diffForHumans() }}</flux:text>
                        @endif
                    </div>
                </button>
            @empty
                <flux:text class="text-zinc-500">{{ $emptyText }}</flux:text>
            @endforelse
        </div>
    @endforeach

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
                    <flux:text class="text-zinc-500">
                        {{ $reservation->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $reservation->unit->unit_number]) }}
                    </flux:text>
                </div>
                <flux:badge size="sm" :color="$reservation->status->color()">{{ $reservation->status->label() }}</flux:badge>
            </button>
        @empty
            <flux:text class="text-zinc-500">{{ __('Nothing finished yet.') }}</flux:text>
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

                @if ($reservation->status === ReservationStatus::Reserved)
                    @if ($reservation->hasDownpaymentSent())
                        <flux:callout icon="banknotes" color="blue">
                            <flux:callout.text>{{ __('The applicant sent the downpayment. The unit stays held for them until you confirm or cancel.') }}</flux:callout.text>
                        </flux:callout>
                    @elseif ($reservation->isOverdue())
                        <flux:callout icon="clock" color="red">
                            <flux:callout.text>{{ __('The deadline (:deadline) passed with no downpayment, so the unit is open to others again. It will be marked expired within the hour.', ['deadline' => $reservation->deadlineForDisplay()]) }}</flux:callout.text>
                        </flux:callout>
                    @else
                        <flux:callout icon="clock">
                            <flux:callout.text>{{ __('Held until :deadline while the applicant pays the downpayment.', ['deadline' => $reservation->deadlineForDisplay()]) }}</flux:callout.text>
                        </flux:callout>
                    @endif
                @endif

                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-500">{{ __('Unit') }}</dt>
                        <dd>{{ $reservation->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $reservation->unit->unit_number]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Reference code') }}</dt>
                        <dd>{{ $reservation->code }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Email') }}</dt>
                        <dd>{{ $reservation->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Mobile number') }}</dt>
                        <dd>
                            @if ($reservation->contact_number)
                                <flux:link href="tel:{{ $reservation->contact_number }}">{{ PhilippineMobileNumber::forDisplay($reservation->contact_number) }}</flux:link>
                            @else
                                {{ __('Not given') }}
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Stay') }}</dt>
                        <dd>{{ $reservation->stay_type ? __($reservation->stay_type->label()) : __('Not given') }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Requested username') }}</dt>
                        <dd>{{ $reservation->desired_username }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Age') }}</dt>
                        <dd>{{ $reservation->age }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">{{ __('Address') }}</dt>
                        <dd>{{ $reservation->address }}</dd>
                    </div>
                </dl>

                @if ($reservation->hasFiles())
                    <a href="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'id']) }}" target="_blank" rel="noopener" class="block sm:w-1/2">
                        <img
                            src="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'id']) }}"
                            alt="{{ __('Valid ID') }}"
                            class="max-h-72 w-full rounded-md border border-zinc-200 object-contain dark:border-zinc-700"
                        >
                        <flux:text class="mt-1 text-xs text-zinc-500">{{ __('Valid ID') }}</flux:text>
                    </a>
                @else
                    <flux:text class="text-zinc-500">{{ __('The ID and payment proof were deleted after the retention period.') }}</flux:text>
                @endif

                @if ($reservation->hasDownpaymentSent())
                    <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Downpayment') }}</flux:heading>
                        <dl class="grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-zinc-500">{{ __('Amount') }}</dt>
                                <dd>&#8369;{{ number_format((float) $reservation->downpayment_amount, 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">{{ __('Paid to') }}</dt>
                                <dd>{{ $reservation->paymentChannel ? $reservation->paymentChannel->method->label().' · '.$reservation->paymentChannel->account_name : $reservation->downpayment_method?->label() }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">{{ __('Reference number') }}</dt>
                                <dd>{{ $reservation->downpayment_reference }}</dd>
                            </div>
                            <div>
                                <dt class="text-zinc-500">{{ __('Sent') }}</dt>
                                <dd>{{ $reservation->downpayment_submitted_at?->timezone(config('occuplace.display_timezone'))->format('M j, Y, g:i A') }}</dd>
                            </div>
                        </dl>

                        @if ($reservation->hasFiles() && $reservation->downpayment_proof_path)
                            <a href="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'proof']) }}" target="_blank" rel="noopener" class="block sm:w-1/2">
                                <img
                                    src="{{ route('reservations.files', ['reservation' => $reservation->id, 'kind' => 'proof']) }}"
                                    alt="{{ __('Proof of payment') }}"
                                    class="max-h-72 w-full rounded-md border border-zinc-200 object-contain dark:border-zinc-700"
                                >
                                <flux:text class="mt-1 text-xs text-zinc-500">{{ __('Proof of payment') }}</flux:text>
                            </a>
                        @endif
                    </div>
                @endif

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
                            <flux:textarea wire:model="rejectReason" :label="__('Reason (sent to the applicant)')" rows="3" required />
                            <flux:error name="reason" />

                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="$set('rejecting', false)">{{ __('Back') }}</flux:button>
                                <flux:button variant="danger" wire:click="reject">{{ __('Reject reservation') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <flux:text class="text-zinc-500">
                                {{ trans_choice('Accepting holds the unit for :count day and emails the applicant how to pay the downpayment.|Accepting holds the unit for :count days and emails the applicant how to pay the downpayment.', $this->team->reservation_hold_days) }}
                            </flux:text>
                            <div class="flex gap-2">
                                <flux:button wire:click="startRejecting">{{ __('Reject') }}</flux:button>
                                <flux:button variant="primary" wire:click="accept">{{ __('Accept') }}</flux:button>
                            </div>
                        </div>
                    @endif
                @endif

                @if ($this->canReview && $reservation->status === ReservationStatus::Reserved && $reservation->hasDownpaymentSent())
                    <div class="space-y-3">
                        <flux:checkbox wire:model="downpaymentConfirmed" :label="__('I checked that this downpayment arrived in my account')" />

                        <div class="flex justify-end">
                            <flux:button variant="primary" wire:click="confirm">{{ __('Confirm reservation') }}</flux:button>
                        </div>
                    </div>
                @endif

                @if ($this->canReview && $reservation->status === ReservationStatus::Reserved && ! $reservation->hasDownpaymentSent())
                    <div class="flex flex-wrap items-end justify-end gap-2">
                        <flux:input wire:model="extendDays" type="number" min="{{ config('occuplace.reservations.hold_days.min') }}" max="{{ config('occuplace.reservations.hold_days.max') }}" :label="__('Give more days')" class="max-w-32" />
                        <flux:button wire:click="extend">{{ __('Extend deadline') }}</flux:button>
                    </div>
                    <flux:error name="extendDays" />
                @endif

                @if ($reservation->status === ReservationStatus::Confirmed && $reservation->tenant_user_id)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-zinc-500">
                            {{ __('The unit is held for this tenant. Move them in when they arrive to start their lease and rent.') }}
                        </flux:text>
                        <flux:button variant="primary" icon="home" :href="route('tenants', ['tenant' => $reservation->tenant_user_id])" wire:navigate>
                            {{ __('Move in') }}
                        </flux:button>
                    </div>
                @endif

                @if ($this->canReview && in_array($reservation->status, [ReservationStatus::Reserved, ReservationStatus::Confirmed], true))
                    @if ($cancelling)
                        <div class="space-y-3">
                            <flux:callout variant="warning" icon="exclamation-triangle">
                                <flux:callout.text>
                                    {{ $reservation->status === ReservationStatus::Confirmed
                                        ? __('Cancelling releases the slot and disables the applicant\'s account. If they already sent a downpayment, the refund is handled outside Occuplace.')
                                        : __('Cancelling releases the slot. If they already sent a downpayment, the refund is handled outside Occuplace.') }}
                                </flux:callout.text>
                            </flux:callout>

                            <flux:textarea wire:model="cancelReason" :label="__('Reason for cancelling (sent to the applicant)')" rows="3" required />
                            <flux:error name="cancelReason" />

                            <div class="flex justify-end gap-2">
                                <flux:button wire:click="$set('cancelling', false)">{{ __('Back') }}</flux:button>
                                <flux:button variant="danger" wire:click="cancel">{{ __('Cancel reservation') }}</flux:button>
                            </div>
                        </div>
                    @else
                        <div class="flex justify-end">
                            <flux:button variant="danger" wire:click="startCancelling">{{ __('Cancel reservation') }}</flux:button>
                        </div>
                    @endif
                @endif

                @if ($reservation->cancellation_reason)
                    <flux:callout icon="x-circle">
                        <flux:callout.heading>{{ __('Cancelled') }}</flux:callout.heading>
                        <flux:callout.text>{{ $reservation->cancellation_reason }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($this->canReview && $reservation->status === ReservationStatus::Confirmed && $reservation->tenant?->must_change_password)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:text class="text-zinc-500">
                            {{ __('The tenant has not logged in yet. Send a fresh temporary password if the email never arrived or expired.') }}
                        </flux:text>
                        <flux:button wire:click="resendLoginDetails">{{ __('Resend login details') }}</flux:button>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</section>
