<?php

use App\Enums\ReservationStatus;
use App\Enums\StayType;
use App\Enums\TeamRole;
use App\Models\PaymentChannel;
use App\Models\Reservation;
use App\Models\UnitListing;
use App\Models\User;
use App\Notifications\ReservationSubmitted;
use App\Rules\PhilippineMobileNumber;
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

    public string $contact_number = '';

    public string $age = '';

    public string $address = '';

    public string $stay_type = '';

    public $valid_id = null;

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

        $validated = $this->validate($this->reservationRules($unit->id), $this->messages());

        $reservation = DB::transaction(function () use ($validated, $listing, $unit, $teamId) {
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
                'contact_number' => PhilippineMobileNumber::normalize($validated['contact_number']),
                'age' => (int) $validated['age'],
                'address' => trim($validated['address']),
                'stay_type' => StayType::from($validated['stay_type']),
                'valid_id_path' => $this->valid_id->store("reservations/{$code}", $disk),
                'consented_at' => now(),
            ]);
        });

        $unit->property->team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->get()
            ->each(fn (User $member) => $member->notify(new ReservationSubmitted($reservation)));

        RateLimiter::hit($submissionsKey, 3600);

        session()->push(Reservation::STATUS_ACCESS_SESSION_KEY, $reservation->code);

        $this->submittedCode = $reservation->code;
        $this->reset('desired_username', 'email', 'first_name', 'last_name', 'contact_number', 'age', 'address', 'stay_type', 'valid_id', 'consent');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function reservationRules(int $unitId): array
    {
        $inProgress = [ReservationStatus::Pending->value, ReservationStatus::Reserved->value];

        return [
            'desired_username' => [
                'required',
                'regex:/^[a-z0-9._]{4,30}$/',
                Rule::unique('users', 'username'),
                Rule::unique('reservations', 'desired_username')->whereIn('status', $inProgress),
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                fn (string $attribute, mixed $value, Closure $fail) => User::whereRaw('lower(email) = ?', [Str::lower((string) $value)])->exists()
                    ? $fail(__('This email already has an account. Log in instead, or contact the landlord.'))
                    : null,
                Rule::unique('reservations', 'email')->whereIn('status', $inProgress)->where('unit_id', $unitId),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:20', new PhilippineMobileNumber],
            'age' => ['required', 'integer', 'min:'.self::MINIMUM_AGE, 'max:120'],
            'address' => ['required', 'string', 'max:500'],
            'stay_type' => ['required', Rule::enum(StayType::class)],
            'valid_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
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
            'email.unique' => __('You already have a reservation in progress for this unit.'),
            'age.min' => __('You must be at least :age years old to reserve.', ['age' => self::MINIMUM_AGE]),
            'stay_type.required' => __('Choose how long you plan to stay.'),
            'valid_id.required' => __('Upload a photo or scan of a valid ID.'),
            'consent.accepted' => __('You need to agree so the landlord can review your application.'),
        ];
    }
}; ?>

<section class="mx-auto w-full max-w-3xl px-6 pb-16 sm:px-8">
    @php($unit = $this->listing->unit)
    @php($property = $unit->property)

    <a href="{{ route('listings.show', $this->listing) }}" class="text-sm text-zinc-600 transition-colors hover:text-zinc-900">&larr; {{ __('Back to the listing') }}</a>

    <h1 class="mt-4 text-3xl font-semibold tracking-tight text-zinc-900">{{ __('Reserve this unit') }}</h1>
    <p class="mt-1 text-zinc-600">
        {{ $this->listing->title }} &middot; {{ $property->name }} &middot; {{ __('Unit :number', ['number' => $unit->unit_number]) }}
    </p>

    @if ($submittedCode)
        <div class="mt-8 rounded-xl border border-sand bg-white p-8 text-center">
            <h2 class="text-xl font-semibold text-zinc-900">{{ __('Reservation sent') }}</h2>
            <p class="mt-2 text-zinc-700">{{ __('Keep this reference code:') }}</p>
            <p class="mt-3 font-mono text-3xl tracking-widest text-zinc-900">{{ $submittedCode }}</p>
            <p class="mx-auto mt-4 max-w-md text-sm text-zinc-600">
                {{ __('The landlord will review your details. If they accept, we email you how to pay the downpayment and by when. Don\'t pay anything until then.') }}
            </p>
            <a href="{{ route('reservations.status', ['code' => $submittedCode]) }}" class="mt-6 inline-block rounded-lg bg-brand-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-brand-500">
                {{ __('See your reservation') }}
            </a>
            <p class="mt-3 text-xs text-zinc-600">{{ __('You can also open it later from Check your reservation, with this code and your email.') }}</p>
        </div>
    @elseif ($this->channels->isEmpty())
        <div class="mt-8 rounded-xl border border-sand bg-white p-8 text-center">
            <h2 class="font-semibold text-zinc-900">{{ __('Reservations are closed for now') }}</h2>
            <p class="mt-2 text-sm text-zinc-600">{{ __('This landlord is not accepting online reservations right now.') }}</p>
        </div>
    @else
        <form wire:submit="submit" class="mt-8 flex flex-col gap-8" novalidate>
            @error('form')
                <p class="rounded-lg border border-red-300 p-3 text-sm text-red-700">{{ $message }}</p>
            @enderror

            <div class="hidden" aria-hidden="true">
                <label for="website">{{ __('Leave this field empty') }}</label>
                <input id="website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="rounded-xl border border-sand bg-sand/30 p-6">
                <h2 class="font-semibold text-zinc-900">{{ __('How reserving works') }}</h2>
                <ol class="mt-3 list-decimal space-y-1 ps-5 text-sm text-zinc-700">
                    <li>{{ __('Send your details below. Reserving is free.') }}</li>
                    <li>
                        {{ trans_choice('If the landlord accepts, the unit is held for you for :count day and we email you how to pay the downpayment.|If the landlord accepts, the unit is held for you for :count days and we email you how to pay the downpayment.', $property->team->reservation_hold_days) }}
                        @if ($this->listing->downpayment_amount !== null)
                            {{ __('The downpayment is :amount.', ['amount' => '₱'.number_format((float) $this->listing->downpayment_amount, 2)]) }}
                        @endif
                    </li>
                    <li>{{ __('Once the landlord confirms your downpayment, your login details are emailed to you.') }}</li>
                </ol>
                <p class="mt-3 text-sm font-medium text-amber-800">{{ __('Don\'t pay anything until the landlord accepts.') }}</p>
            </div>

            <fieldset class="rounded-xl border border-sand bg-white p-6">
                <legend class="px-2 text-lg font-semibold text-zinc-900">{{ __('Your details') }}</legend>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="first_name" class="mb-1 block text-sm text-zinc-700">{{ __('First name') }}</label>
                        <input id="first_name" type="text" maxlength="100" wire:model="first_name" autocomplete="given-name" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                        @error('first_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="last_name" class="mb-1 block text-sm text-zinc-700">{{ __('Last name') }}</label>
                        <input id="last_name" type="text" maxlength="100" wire:model="last_name" autocomplete="family-name" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                        @error('last_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="age" class="mb-1 block text-sm text-zinc-700">{{ __('Age') }}</label>
                        <input id="age" type="number" min="0" max="120" wire:model="age" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                        @error('age') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email" class="mb-1 block text-sm text-zinc-700">{{ __('Email') }}</label>
                        <input id="email" type="email" maxlength="255" wire:model="email" autocomplete="email" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                        <p class="mt-1 text-xs text-zinc-600">{{ __('Your login details are sent here if you are approved.') }}</p>
                        @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="contact_number" class="mb-1 block text-sm text-zinc-700">{{ __('Mobile number') }}</label>
                        <input id="contact_number" type="tel" x-mask="99999999999" wire:model="contact_number" autocomplete="tel-national" inputmode="numeric" placeholder="09171234567" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                        <p class="mt-1 text-xs text-zinc-600">{{ __('The landlord uses this to reach you about the reservation.') }}</p>
                        @error('contact_number') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="address" class="mb-1 block text-sm text-zinc-700">{{ __('Home address') }}</label>
                        <input id="address" type="text" maxlength="500" wire:model="address" autocomplete="street-address" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                        @error('address') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <fieldset class="sm:col-span-2">
                        <legend class="mb-1 text-sm text-zinc-700">{{ __('How long do you plan to stay?') }}</legend>
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach (StayType::cases() as $stayType)
                                <label wire:key="stay-{{ $stayType->value }}" class="flex cursor-pointer items-start gap-3 rounded-lg border border-zinc-300 p-3 has-[:checked]:border-brand-500">
                                    <input type="radio" wire:model="stay_type" value="{{ $stayType->value }}" class="mt-0.5 size-4">
                                    <span>
                                        <span class="block text-sm font-medium text-zinc-900">{{ __($stayType->label()) }}</span>
                                        <span class="block text-xs text-zinc-600">{{ __($stayType->description()) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('stay_type') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </fieldset>

                    <div class="sm:col-span-2">
                        <label for="desired_username" class="mb-1 block text-sm text-zinc-700">{{ __('Username you want to log in with') }}</label>
                        <input id="desired_username" type="text" maxlength="30" wire:model="desired_username" autocomplete="off" autocapitalize="none" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
                        <p class="mt-1 text-xs text-zinc-600">{{ __('4 to 30 lowercase letters, numbers, dots or underscores.') }}</p>
                        @error('desired_username') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label for="valid_id" class="mb-1 block text-sm text-zinc-700">{{ __('Valid ID (photo or scan)') }}</label>
                        <input id="valid_id" type="file" accept="image/jpeg,image/png,application/pdf" wire:model="valid_id" class="w-full text-sm text-zinc-700">
                        <p class="mt-1 text-xs text-zinc-600">{{ __('JPG, PNG or PDF, up to 5 MB. Only the landlord can see this.') }}</p>
                        @error('valid_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            <div>
                <label class="flex items-start gap-3 text-sm text-zinc-700">
                    <input type="checkbox" wire:model="consent" class="mt-1 size-4">
                    <span>
                        {{ __('I agree that my ID, payment proof and personal details will be shared with this landlord so they can screen my application. They are used for that purpose only, in line with the Data Privacy Act.') }}
                    </span>
                </label>
                @error('consent') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit,valid_id"
                    class="w-full rounded-lg bg-brand-600 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-brand-500 disabled:opacity-60 sm:w-auto"
                >
                    <span wire:loading.remove wire:target="submit">{{ __('Send reservation') }}</span>
                    <span wire:loading wire:target="submit">{{ __('Sending...') }}</span>
                </button>
            </div>
        </form>
    @endif
</section>
