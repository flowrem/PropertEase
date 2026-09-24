<?php

use App\Enums\TeamRole;
use App\Models\PaymentChannel;
use App\Models\Reservation;
use App\Models\UnitListing;
use App\Models\User;
use App\Notifications\ReservationSubmitted;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::public'), Title('Reserve this unit')] class extends Component
{
    use WithFileUploads;

    public const MINIMUM_AGE = 18;

    private const SUBMISSIONS_PER_HOUR = 5;

    private const ATTEMPTS_PER_HOUR = 30;

    #[Locked]
    public int $listingId;

    public string $desired_username = '';

    public string $email = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $age = '';

    public string $address = '';

    public $valid_id = null;

    public string $downpayment_amount = '';

    public ?int $payment_channel_id = null;

    public string $downpayment_reference = '';

    public $proof = null;

    public bool $consent = false;

    /**
     * Honeypot: real people never see or fill this.
     */
    public string $website = '';

    public ?string $submittedCode = null;

    public function mount(int $listing): void
    {
        $this->listingId = $listing;

        // Resolve once on load so a hidden or full listing 404s immediately.
        $this->listing;
    }

    /**
     * Re-resolved on every request, so a listing that stops being public
     * mid-session can no longer be reserved.
     */
    #[Computed]
    public function listing(): UnitListing
    {
        return UnitListing::query()
            ->publiclyVisible()
            ->with(['unit.property.team'])
            ->findOrFail($this->listingId);
    }

    /**
     * @return Collection<int, PaymentChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return PaymentChannel::query()
            ->where('team_id', $this->listing->unit->property->team_id)
            ->active()
            ->orderBy('id')
            ->get();
    }

    public function submit(): void
    {
        $attemptsKey = 'reservation-attempts:'.request()->ip();
        $submissionsKey = 'reservation-submissions:'.request()->ip();

        if (RateLimiter::tooManyAttempts($attemptsKey, self::ATTEMPTS_PER_HOUR)
            || RateLimiter::tooManyAttempts($submissionsKey, self::SUBMISSIONS_PER_HOUR)) {
            $this->addError('form', __('Too many reservation attempts from this connection. Please try again in an hour.'));

            return;
        }

        RateLimiter::hit($attemptsKey, 3600);

        if ($this->website !== '') {
            $this->submittedCode = Reservation::generateCode();

            return;
        }

        $listing = $this->listing;
        $unit = $listing->unit;
        $teamId = $unit->property->team_id;

        abort_if($this->channels->isEmpty(), 404);

        $this->desired_username = Str::lower(trim($this->desired_username));
        $this->email = Str::lower(trim($this->email));

        $validated = $this->validate($this->reservationRules($listing, $teamId, $unit->id), $this->messages());

        $channel = $this->channels->firstWhere('id', $validated['payment_channel_id']);
        $reservation = DB::transaction(function () use ($validated, $listing, $unit, $teamId, $channel) {
            $code = Reservation::generateCode();
            $disk = config('filesystems.sensitive_disk');

            return Reservation::create([
                'code' => $code,
                'unit_listing_id' => $listing->id,
                'unit_id' => $unit->id,
                'team_id' => $teamId,
                'desired_username' => $validated['desired_username'],
                'email' => $validated['email'],
                'first_name' => trim($validated['first_name']),
                'last_name' => trim($validated['last_name']),
                'age' => (int) $validated['age'],
                'address' => trim($validated['address']),
                'valid_id_path' => $this->valid_id->store("reservations/{$code}", $disk),
                'downpayment_amount' => $validated['downpayment_amount'],
                'payment_channel_id' => $channel->id,
                'downpayment_method' => $channel->method,
                'downpayment_reference' => trim($validated['downpayment_reference']),
                'downpayment_proof_path' => $this->proof->store("reservations/{$code}", $disk),
                'consented_at' => now(),
            ]);
        });

        $unit->property->team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->get()
            ->each(fn (User $member) => $member->notify(new ReservationSubmitted($reservation)));

        RateLimiter::hit($submissionsKey, 3600);

        $this->submittedCode = $reservation->code;
        $this->reset('desired_username', 'email', 'first_name', 'last_name', 'age', 'address', 'valid_id', 'downpayment_amount', 'payment_channel_id', 'downpayment_reference', 'proof', 'consent');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function reservationRules(UnitListing $listing, int $teamId, int $unitId): array
    {
        $minimumAmount = $listing->downpayment_amount !== null ? (float) $listing->downpayment_amount : 0.01;

        return [
            'desired_username' => [
                'required',
                'regex:/^[a-z0-9._]{4,30}$/',
                Rule::unique('users', 'username'),
                Rule::unique('reservations', 'desired_username')->where('status', 'pending'),
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                fn (string $attribute, mixed $value, Closure $fail) => User::whereRaw('lower(email) = ?', [Str::lower((string) $value)])->exists()
                    ? $fail(__('This email already has an account. Log in instead, or contact the landlord.'))
                    : null,
                Rule::unique('reservations', 'email')->where('status', 'pending')->where('unit_id', $unitId),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'age' => ['required', 'integer', 'min:'.self::MINIMUM_AGE, 'max:120'],
            'address' => ['required', 'string', 'max:500'],
            'valid_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'downpayment_amount' => ['required', 'numeric', 'min:'.$minimumAmount, 'max:99999999'],
            'payment_channel_id' => [
                'required',
                Rule::exists('payment_channels', 'id')->where('team_id', $teamId)->where('is_active', true),
            ],
            'downpayment_reference' => ['required', 'string', 'min:4', 'max:50'],
            'proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'consent' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'desired_username.regex' => __('Use 4 to 30 lowercase letters, numbers, dots or underscores.'),
            'desired_username.unique' => __('That username is taken. Try another.'),
            'email.unique' => __('You already have a pending reservation for this unit.'),
            'age.min' => __('You must be at least :age years old to reserve.', ['age' => self::MINIMUM_AGE]),
            'payment_channel_id.required' => __('Choose the account you paid to.'),
            'proof.required' => __('Upload a screenshot or photo of your payment receipt.'),
            'valid_id.required' => __('Upload a photo or scan of a valid ID.'),
            'consent.accepted' => __('You need to agree so the landlord can review your application.'),
        ];
    }
}; ?>

<section class="mx-auto w-full max-w-3xl px-6 pb-16 sm:px-8">
    @php($unit = $this->listing->unit)
    @php($property = $unit->property)

    <a href="{{ route('listings.show', $this->listing) }}" class="text-sm text-zinc-400 transition-colors hover:text-white">&larr; {{ __('Back to the listing') }}</a>

    <h1 class="mt-4 text-3xl font-semibold tracking-tight text-white">{{ __('Reserve this unit') }}</h1>
    <p class="mt-1 text-zinc-400">
        {{ $this->listing->title }} &middot; {{ $property->name }} &middot; {{ __('Unit :number', ['number' => $unit->unit_number]) }}
    </p>

    @if ($submittedCode)
        <div class="mt-8 rounded-xl border border-zinc-800 bg-brand-800 p-8 text-center">
            <h2 class="text-xl font-semibold text-white">{{ __('Reservation sent') }}</h2>
            <p class="mt-2 text-zinc-300">{{ __('Keep this reference code:') }}</p>
            <p class="mt-3 font-mono text-3xl tracking-widest text-white">{{ $submittedCode }}</p>
            <p class="mx-auto mt-4 max-w-md text-sm text-zinc-400">
                {{ __('The landlord will review your details and payment. If approved, your login details will be sent to the email you gave. Nothing is confirmed until then.') }}
            </p>
        </div>
    @elseif ($this->channels->isEmpty())
        <div class="mt-8 rounded-xl border border-zinc-800 bg-brand-800 p-8 text-center">
            <h2 class="font-semibold text-white">{{ __('Reservations are closed for now') }}</h2>
            <p class="mt-2 text-sm text-zinc-400">{{ __('This landlord is not accepting online reservations right now.') }}</p>
        </div>
    @else
        <form wire:submit="submit" class="mt-8 flex flex-col gap-8" novalidate>
            @error('form')
                <p class="rounded-lg border border-red-400/40 p-3 text-sm text-red-400">{{ $message }}</p>
            @enderror

            <div class="hidden" aria-hidden="true">
                <label for="website">{{ __('Leave this field empty') }}</label>
                <input id="website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <fieldset class="rounded-xl border border-zinc-800 bg-brand-800 p-6">
                <legend class="px-2 text-lg font-semibold text-white">{{ __('1. Pay the downpayment') }}</legend>

                <p class="text-zinc-300">
                    @if ($this->listing->downpayment_amount !== null)
                        {{ __('Downpayment requested:') }}
                        <span class="font-semibold text-white">&#8369;{{ number_format((float) $this->listing->downpayment_amount, 2) }}</span>
                    @else
                        {{ __('The landlord did not set an amount. Ask them, then pay it below.') }}
                    @endif
                </p>
                <p class="mt-1 text-sm text-amber-300">{{ __('Check that the account name matches the landlord before paying.') }}</p>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    @foreach ($this->channels as $channel)
                        <label wire:key="channel-{{ $channel->id }}" class="flex cursor-pointer flex-col gap-3 rounded-lg border border-zinc-700 p-4 has-[:checked]:border-brand-500">
                            <span class="flex items-center gap-2">
                                <input type="radio" wire:model="payment_channel_id" value="{{ $channel->id }}" class="size-4">
                                <span class="font-semibold text-white">{{ $channel->method->label() }}</span>
                            </span>

                            @if ($channel->qrUrl())
                                <img
                                    src="{{ $channel->qrUrl() }}"
                                    alt="{{ __(':method QR code for :name', ['method' => $channel->method->label(), 'name' => $channel->account_name]) }}"
                                    width="256"
                                    height="256"
                                    class="mx-auto size-64 max-w-full rounded bg-white object-contain"
                                >
                            @endif

                            <span class="text-sm text-zinc-300">
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
                @error('payment_channel_id') <p class="mt-2 text-sm text-red-400">{{ $message }}</p> @enderror
            </fieldset>

            <fieldset class="rounded-xl border border-zinc-800 bg-brand-800 p-6">
                <legend class="px-2 text-lg font-semibold text-white">{{ __('2. Your payment') }}</legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="downpayment_amount" class="mb-1 block text-sm text-zinc-300">{{ __('Amount you paid (PHP)') }}</label>
                        <input id="downpayment_amount" type="number" step="0.01" min="0" wire:model="downpayment_amount" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        @error('downpayment_amount') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="downpayment_reference" class="mb-1 block text-sm text-zinc-300">{{ __('Reference number') }}</label>
                        <input id="downpayment_reference" type="text" maxlength="50" wire:model="downpayment_reference" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        @error('downpayment_reference') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="proof" class="mb-1 block text-sm text-zinc-300">{{ __('Proof of payment (screenshot or photo of the receipt)') }}</label>
                        <input id="proof" type="file" accept="image/jpeg,image/png,image/webp" wire:model="proof" class="w-full text-sm text-zinc-300">
                        <p class="mt-1 text-xs text-zinc-400">{{ __('JPG, PNG or WebP, up to 5 MB. Only the landlord can see this.') }}</p>
                        @error('proof') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            <fieldset class="rounded-xl border border-zinc-800 bg-brand-800 p-6">
                <legend class="px-2 text-lg font-semibold text-white">{{ __('3. About you') }}</legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="first_name" class="mb-1 block text-sm text-zinc-300">{{ __('First name') }}</label>
                        <input id="first_name" type="text" maxlength="100" wire:model="first_name" autocomplete="given-name" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        @error('first_name') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="last_name" class="mb-1 block text-sm text-zinc-300">{{ __('Last name') }}</label>
                        <input id="last_name" type="text" maxlength="100" wire:model="last_name" autocomplete="family-name" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        @error('last_name') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="age" class="mb-1 block text-sm text-zinc-300">{{ __('Age') }}</label>
                        <input id="age" type="number" min="0" max="120" wire:model="age" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        @error('age') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email" class="mb-1 block text-sm text-zinc-300">{{ __('Email') }}</label>
                        <input id="email" type="email" maxlength="255" wire:model="email" autocomplete="email" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        <p class="mt-1 text-xs text-zinc-400">{{ __('Your login details are sent here if you are approved.') }}</p>
                        @error('email') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="address" class="mb-1 block text-sm text-zinc-300">{{ __('Home address') }}</label>
                        <input id="address" type="text" maxlength="500" wire:model="address" autocomplete="street-address" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        @error('address') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="desired_username" class="mb-1 block text-sm text-zinc-300">{{ __('Username you want to log in with') }}</label>
                        <input id="desired_username" type="text" maxlength="30" wire:model="desired_username" autocomplete="off" autocapitalize="none" class="w-full rounded-lg border border-zinc-700 bg-brand-900 px-3 py-2 text-sm text-white">
                        <p class="mt-1 text-xs text-zinc-400">{{ __('4 to 30 lowercase letters, numbers, dots or underscores.') }}</p>
                        @error('desired_username') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="valid_id" class="mb-1 block text-sm text-zinc-300">{{ __('Valid ID (photo or scan)') }}</label>
                        <input id="valid_id" type="file" accept="image/jpeg,image/png,application/pdf" wire:model="valid_id" class="w-full text-sm text-zinc-300">
                        <p class="mt-1 text-xs text-zinc-400">{{ __('JPG, PNG or PDF, up to 5 MB. Only the landlord can see this.') }}</p>
                        @error('valid_id') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            <div>
                <label class="flex items-start gap-3 text-sm text-zinc-300">
                    <input type="checkbox" wire:model="consent" class="mt-1 size-4">
                    <span>
                        {{ __('I agree that my ID, payment proof and personal details will be shared with this landlord so they can screen my application. They are used for that purpose only, in line with the Data Privacy Act.') }}
                    </span>
                </label>
                @error('consent') <p class="mt-1 text-sm text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit,valid_id,proof"
                    class="w-full rounded-lg bg-brand-600 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-brand-500 disabled:opacity-60 sm:w-auto"
                >
                    <span wire:loading.remove wire:target="submit">{{ __('Send reservation') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Sending...') }}</span>
                </button>
            </div>
        </form>
    @endif
</section>
