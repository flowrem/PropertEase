<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-brand-900 font-sans antialiased">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-6 sm:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-2" wire:navigate>
                <span class="flex size-8 items-center justify-center rounded-lg bg-brand-600">
                    <x-app-logo-icon class="size-4 fill-current text-white" />
                </span>
                <span class="text-sm font-semibold tracking-tight text-white">{{ config('app.name') }}</span>
            </a>

            @if (Route::has('login'))
                <nav class="flex items-center gap-2">
                    @auth
                        <a
                            href="{{ route('dashboard') }}"
                            class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-500"
                            wire:navigate
                        >
                            {{ __('Dashboard') }}
                        </a>
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-zinc-300 transition-colors hover:text-white"
                            wire:navigate
                        >
                            {{ __('Log in') }}
                        </a>

                        @if (Route::has('register'))
                            <a
                                href="{{ route('register') }}"
                                class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-500"
                                wire:navigate
                            >
                                {{ __('Create your account') }}
                            </a>
                        @endif
                    @endauth
                </nav>
            @endif
        </header>

        <main>
            <section class="mx-auto w-full max-w-3xl px-6 pt-16 pb-20 text-center sm:px-8 sm:pt-24">
                <h1 class="text-4xl font-semibold tracking-tight text-white sm:text-5xl">
                    {{ __('Stop running your property over group chat.') }}
                </h1>
                <p class="mx-auto mt-5 max-w-xl text-lg text-zinc-400">
                    {{ __('Occuplace gives landlords and tenants one shared place for rent, maintenance requests, and complaints, so nothing gets lost in a text thread.') }}
                </p>

                @if (Route::has('register'))
                    <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <a
                            href="{{ route('register') }}"
                            class="w-full rounded-lg bg-brand-600 px-6 py-3 text-sm font-medium text-white transition-colors hover:bg-brand-500 sm:w-auto"
                            wire:navigate
                        >
                            {{ __('Create your account') }}
                        </a>
                        <a
                            href="{{ route('login') }}"
                            class="w-full rounded-lg border border-zinc-700 px-6 py-3 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-500 hover:text-white sm:w-auto"
                            wire:navigate
                        >
                            {{ __('Log in') }}
                        </a>
                    </div>
                @endif
            </section>

            <section class="border-t border-brand-600/20">
                <div class="mx-auto grid w-full max-w-5xl gap-6 px-6 py-16 sm:px-8 md:grid-cols-3">
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

                    <div class="rounded-xl border border-zinc-800 bg-brand-800 p-6">
                        <span class="flex size-10 items-center justify-center rounded-lg bg-zinc-900">
                            <flux:icon name="megaphone" class="size-5 text-brand-500" />
                        </span>
                        <h2 class="mt-4 font-semibold text-white">{{ __('One announcement, everyone sees it') }}</h2>
                        <p class="mt-2 text-sm text-zinc-400">
                            {{ __('Post a notice once. Every tenant on the property gets it, no more relaying the same message one by one.') }}
                        </p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="mx-auto w-full max-w-5xl px-6 py-10 text-center text-sm text-zinc-400 sm:px-8">
            &copy; {{ date('Y') }} {{ config('app.name') }}
        </footer>

        @fluxAppearance
    </body>
</html>
