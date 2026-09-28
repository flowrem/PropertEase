<?php

use App\Actions\Reservations\SubmitDownpayment;
use App\Enums\ReservationStatus;
use App\Models\PaymentChannel;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::public'), Title('Your reservation')] class extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $code;

    public ?int $payment_channel_id = null;

    public string $downpayment_amount = '';

    public string $downpayment_reference = '';

    public $proof = null;

    /**
     * The emailed link is signed and opens the page directly; anyone else is
     * sent to the lookup form to give the code and the email used.
     */
    public function mount(string $code): void
    {
        $this->code = Str::upper($code);

        if (request()->hasValidSignature()) {
            abort_unless(Reservation::query()->where('code', $this->code)->exists(), 404);

            session()->push(Reservation::STATUS_ACCESS_SESSION_KEY, $this->code);
        }

        if (! $this->hasAccess()) {
            $this->redirectRoute('reservations.lookup', ['code' => $this->code], navigate: true);
        }
    }

    /**
     * Checked on every request, since only the first one carries the link's signature.
     */
    #[Computed]
    public function reservation(): Reservation
    {
        abort_unless($this->hasAccess(), 403);

        return Reservation::query()
            ->with(['unit.property', 'listing', 'team', 'paymentChannel'])
            ->where('code', $this->code)
            ->firstOrFail();
    }

    /**
     * @return Collection<int, PaymentChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return PaymentChannel::query()
            ->where('team_id', $this->reservation->team_id)
            ->active()
            ->orderBy('id')
            ->get();
    }

    /**
     * The downpayment the listing asks for, or null when the landlord set none.
     */
    #[Computed]
    public function requestedAmount(): ?float
    {
        $amount = $this->reservation->listing?->downpayment_amount;

        return $amount !== null ? (float) $amount : null;
    }

    public function submitDownpayment(SubmitDownpayment $submitDownpayment): void
    {
        $reservation = $this->reservation;

        $validated = $this->validate([
            'payment_channel_id' => [
                'required',
                Rule::exists('payment_channels', 'id')->where('team_id', $reservation->team_id)->where('is_active', true),
            ],
            'downpayment_amount' => ['required', 'numeric', 'min:'.($this->requestedAmount ?? 0.01), 'max:99999999'],
            'downpayment_reference' => ['required', 'string', 'min:4', 'max:50'],
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'payment_channel_id.required' => __('Choose the account you paid to.'),
            'proof.required' => __('Upload a screenshot or photo of your payment receipt.'),
        ]);

        $submitDownpayment->handle(
            $reservation,
            $this->channels->firstOrFail('id', $validated['payment_channel_id']),
            (string) $validated['downpayment_amount'],
            $validated['downpayment_reference'],
            $this->proof,
        );

        $this->reset('payment_channel_id', 'downpayment_amount', 'downpayment_reference', 'proof');
        unset($this->reservation);
    }

    private function hasAccess(): bool
    {
        return in_array($this->code, session()->get(Reservation::STATUS_ACCESS_SESSION_KEY, []), true);
    }
}; ?>

<section class="mx-auto w-full max-w-3xl px-6 pb-16 sm:px-8">
    @php($reservation = $this->reservation)
    @php($unit = $reservation->unit)

    <p class="mt-4 font-mono text-sm tracking-widest text-zinc-600">{{ $reservation->code }}</p>
    <h1 class="mt-1 text-3xl font-semibold tracking-tight text-zinc-900">{{ __('Your reservation') }}</h1>
    <p class="mt-1 text-zinc-600">
        {{ $unit->property->name }} &middot; {{ __('Unit :number', ['number' => $unit->unit_number]) }} &middot; {{ $reservation->team->name }}
    </p>

    <div class="mt-8 rounded-xl border border-sand bg-white p-6">
        @if ($reservation->status === ReservationStatus::Pending)
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('Waiting for the landlord') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('The landlord is reviewing your details. If they accept, we email you and the unit is held for you while you pay the downpayment. Don\'t pay anything yet.') }}</p>
        @elseif ($reservation->status === ReservationStatus::Reserved && $reservation->hasDownpaymentSent())
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('Downpayment sent') }}</h2>
            <p class="mt-2 text-zinc-700">
                {{ __('You sent :amount on :date. The unit stays held for you while the landlord checks it. Once they confirm, your login details are emailed to you.', [
                    'amount' => '₱'.number_format((float) $reservation->downpayment_amount, 2),
                    'date' => $reservation->downpayment_submitted_at?->timezone(config('occuplace.display_timezone'))->format('M j, Y, g:i A'),
                ]) }}
            </p>
        @elseif ($reservation->status === ReservationStatus::Reserved && $reservation->isOverdue())
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('This reservation has ended') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('The downpayment deadline (:deadline) passed, so the unit is open to others again. You can reserve again from the listing if it still has room.', ['deadline' => $reservation->deadlineForDisplay()]) }}</p>
        @elseif ($reservation->status === ReservationStatus::Reserved)
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('The unit is held for you') }}</h2>
            <p class="mt-2 rounded-lg bg-sand/50 p-3 font-medium text-zinc-900">
                {{ __('Pay the downpayment by :deadline or this reservation ends.', ['deadline' => $reservation->deadlineForDisplay()]) }}
            </p>

            <form wire:submit="submitDownpayment" class="mt-6 flex flex-col gap-6" novalidate>
                <div>
                    <h3 class="font-semibold text-zinc-900">{{ __('1. Pay the downpayment') }}</h3>
                    <p class="mt-1 text-zinc-700">
                        @if ($this->requestedAmount !== null)
                            {{ __('Downpayment requested:') }}
                            <span class="font-semibold text-zinc-900">&#8369;{{ number_format($this->requestedAmount, 2) }}</span>
                        @else
                            {{ __('The landlord did not set an amount. Ask them, then pay it below.') }}
                        @endif
                    </p>
                    <p class="mt-1 text-sm text-amber-800">{{ __('Check that the account name matches the landlord before paying.') }}</p>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($this->channels as $channel)
                            <label wire:key="channel-{{ $channel->id }}" class="flex cursor-pointer flex-col gap-3 rounded-lg border border-zinc-300 p-4 has-[:checked]:border-brand-500">
                                <span class="flex items-center gap-2">
                                    <input type="radio" wire:model="payment_channel_id" value="{{ $channel->id }}" class="size-4">
                                    <span class="font-semibold text-zinc-900">{{ $channel->method->label() }}</span>
                                </span>

                                @if ($channel->qrUrl())
                                    <img
                                        src="{{ $channel->qrUrl() }}"
                                        alt="{{ __(':method QR code for :name', ['method' => $channel->method->label(), 'name' => $channel->account_name]) }}"
                                        width="256"
                                        height="256"
                                        loading="lazy"
                                        class="mx-auto size-64 max-w-full rounded bg-white object-contain"
                                    >
                                @endif

                                <span class="text-sm text-zinc-700">
                                    {{ $channel->account_name }}
                                    @if ($channel->bank_name)
                                        <br>{{ $channel->bank_name }}
                                    @endif
                                    @if ($channel->account_number)
                                        <br>{{ $channel->account_number }}
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('payment_channel_id') <p class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <h3 class="font-semibold text-zinc-900">{{ __('2. Send your proof of payment') }}</h3>

                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="downpayment_amount" class="mb-1 block text-sm text-zinc-700">{{ __('Amount you paid (PHP)') }}</label>
                            <input id="downpayment_amount" type="number" step="0.01" min="0" wire:model="downpayment_amount" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                            @error('downpayment_amount') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="downpayment_reference" class="mb-1 block text-sm text-zinc-700">{{ __('Reference number') }}</label>
                            <input id="downpayment_reference" type="text" maxlength="50" wire:model="downpayment_reference" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                            @error('downpayment_reference') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div class="sm:col-span-2">
                            <label for="proof" class="mb-1 block text-sm text-zinc-700">{{ __('Proof of payment (screenshot or photo of the receipt)') }}</label>
                            <input id="proof" type="file" accept="image/jpeg,image/png,image/webp" wire:model="proof" class="w-full text-sm text-zinc-700">
                            <p class="mt-1 text-xs text-zinc-600">{{ __('JPG, PNG or WebP, up to 5 MB. Only the landlord can see this.') }}</p>
                            @error('proof') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="submitDownpayment,proof"
                        class="w-full rounded-lg bg-brand-600 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-brand-500 disabled:opacity-60 sm:w-auto"
                    >
                        <span wire:loading.remove wire:target="submitDownpayment">{{ __('Send downpayment') }}</span>
                        <span wire:loading wire:target="submitDownpayment">{{ __('Sending...') }}</span>
                    </button>
                </div>
            </form>
        @elseif ($reservation->status === ReservationStatus::Confirmed)
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('Confirmed') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('The landlord confirmed your downpayment and the unit is yours. Your login details were emailed to you. The landlord moves you in when you arrive.') }}</p>
            <a href="{{ route('login') }}" class="mt-4 inline-block rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-500">{{ __('Log in') }}</a>
        @elseif ($reservation->status === ReservationStatus::Fulfilled)
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('You have moved in') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('This reservation is done. Log in to see your unit and bills.') }}</p>
            <a href="{{ route('login') }}" class="mt-4 inline-block rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-500">{{ __('Log in') }}</a>
        @elseif ($reservation->status === ReservationStatus::Expired)
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('This reservation has ended') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('No downpayment arrived by the deadline (:deadline), so the unit is open to others again.', ['deadline' => $reservation->deadlineForDisplay()]) }}</p>
        @elseif ($reservation->status === ReservationStatus::Rejected)
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('Not accepted') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('Reason: :reason', ['reason' => $reservation->rejection_reason]) }}</p>
        @elseif ($reservation->status === ReservationStatus::Cancelled)
            <h2 class="text-lg font-semibold text-zinc-900">{{ __('Cancelled') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('Reason: :reason', ['reason' => $reservation->cancellation_reason]) }}</p>
            <p class="mt-2 text-sm text-zinc-600">{{ __('If you already sent a downpayment, contact the landlord about a refund.') }}</p>
        @endif
    </div>
</section>
