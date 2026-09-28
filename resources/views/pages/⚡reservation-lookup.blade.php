<?php

use App\Models\Reservation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::public'), Title('Check your reservation')] class extends Component
{
    private const ATTEMPTS_PER_TEN_MINUTES = 10;

    #[Url]
    public string $code = '';

    public string $email = '';

    /**
     * Open the status page once the code and the email used to reserve both
     * match. The same message covers a wrong code and a wrong email, so the
     * form can't be used to find out which codes exist.
     */
    public function open(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:12'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        $key = 'reservation-lookup:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_TEN_MINUTES)) {
            $this->addError('code', __('Too many tries. Please wait a few minutes and try again.'));

            return;
        }

        RateLimiter::hit($key, 600);

        $code = Str::upper(trim($this->code));

        $found = Reservation::query()
            ->where('code', $code)
            ->whereRaw('lower(email) = ?', [Str::lower(trim($this->email))])
            ->exists();

        if (! $found) {
            $this->addError('code', __('No reservation matches that code and email.'));

            return;
        }

        session()->push(Reservation::STATUS_ACCESS_SESSION_KEY, $code);

        $this->redirectRoute('reservations.status', ['code' => $code], navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-md px-6 pb-16 sm:px-8">
    <h1 class="mt-4 text-3xl font-semibold tracking-tight text-zinc-900">{{ __('Check your reservation') }}</h1>
    <p class="mt-2 text-zinc-600">{{ __('Enter the reference code you got when you reserved, and the email you used.') }}</p>

    <form wire:submit="open" class="mt-8 flex flex-col gap-4 rounded-xl border border-sand bg-white p-6" novalidate>
        <div>
            <label for="code" class="mb-1 block text-sm text-zinc-700">{{ __('Reference code') }}</label>
            <input id="code" type="text" maxlength="12" wire:model="code" autocomplete="off" autocapitalize="characters" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 font-mono text-sm uppercase tracking-widest text-zinc-900">
            @error('code') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="email" class="mb-1 block text-sm text-zinc-700">{{ __('Email') }}</label>
            <input id="email" type="email" maxlength="255" wire:model="email" autocomplete="email" class="w-full rounded-lg border border-zinc-300 bg-brand-50 px-3 py-2 text-sm text-zinc-900">
            @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="rounded-lg bg-brand-600 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-brand-500">
            {{ __('Open my reservation') }}
        </button>
    </form>
</section>
