<x-public-layout>
    <section class="mx-auto w-full max-w-3xl px-6 pt-16 pb-20 text-center sm:px-8 sm:pt-24">
        <h1 class="text-4xl font-semibold tracking-tight text-white sm:text-5xl">
            {{ __('Find a place to stay, or run the one you own.') }}
        </h1>
        <p class="mx-auto mt-5 max-w-xl text-lg text-zinc-400">
            {{ __('Occuplace lists apartments and dorms you can reserve online, and gives landlords one place for rent, maintenance requests, and announcements, so nothing gets lost in a text thread.') }}
        </p>

        <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a
                href="{{ route('listings.index') }}"
                class="w-full rounded-lg bg-brand-600 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-brand-500 sm:w-auto"
            >
                {{ __('Find an apartment or dorm') }}
            </a>
            @if (Route::has('register'))
                <a
                    href="{{ route('register') }}"
                    class="w-full rounded-lg border border-zinc-700 px-6 py-3 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-500 hover:text-white sm:w-auto"
                    wire:navigate
                >
                    {{ __("I'm a landlord") }}
                </a>
            @endif
        </div>
    </section>

    <section class="border-t border-brand-600/20">
        <div class="mx-auto grid w-full max-w-5xl gap-6 px-6 py-16 sm:px-8 md:grid-cols-3">
            <div class="rounded-xl border border-zinc-800 bg-brand-800 p-6">
                <span class="flex size-10 items-center justify-center rounded-lg bg-zinc-900">
                    <flux:icon name="home-modern" class="size-5 text-brand-500" />
                </span>
                <h2 class="mt-4 font-semibold text-white">{{ __('Browse listings, reserve online') }}</h2>
                <p class="mt-2 text-sm text-zinc-400">
                    {{ __('See photos, prices and how many slots are left, then reserve with a downpayment. No account needed to look around.') }}
                </p>
            </div>

            <div class="rounded-xl border border-zinc-800 bg-brand-800 p-6">
                <span class="flex size-10 items-center justify-center rounded-lg bg-zinc-900">
                    <flux:icon name="banknotes" class="size-5 text-brand-500" />
                </span>
                <h2 class="mt-4 font-semibold text-white">{{ __('Billing, out of the group chat') }}</h2>
                <p class="mt-2 text-sm text-zinc-400">
                    {{ __('Rent balance, due dates, and payment history in one place, not scattered across messages.') }}
                </p>
            </div>

            <div class="rounded-xl border border-zinc-800 bg-brand-800 p-6">
                <span class="flex size-10 items-center justify-center rounded-lg bg-zinc-900">
                    <flux:icon name="wrench-screwdriver" class="size-5 text-brand-500" />
                </span>
                <h2 class="mt-4 font-semibold text-white">{{ __("Maintenance requests that don't disappear") }}</h2>
                <p class="mt-2 text-sm text-zinc-400">
                    {{ __('Every repair request is logged with a status, from submitted to resolved, so nothing gets forgotten.') }}
                </p>
            </div>
        </div>
    </section>
</x-public-layout>
