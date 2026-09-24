@props([
    'title' => null,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-screen bg-brand-900 font-sans antialiased">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-6 sm:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-2" wire:navigate>
                <span class="flex size-8 items-center justify-center rounded-lg bg-brand-600">
                    <x-app-logo-icon class="size-4 fill-current text-white" />
                </span>
                <span class="text-sm font-semibold tracking-tight text-white">{{ config('app.name') }}</span>
            </a>

            <nav class="flex items-center gap-1 sm:gap-2">
                <a
                    href="{{ route('listings.index') }}"
                    class="rounded-lg px-3 py-2 text-sm font-medium text-zinc-300 transition-colors hover:text-white sm:px-4"
                >
                    {{ __('Find a place') }}
                </a>

                @auth
                    <a
                        href="{{ auth()->user()->is_super_admin ? route('admin.dashboard') : route('dashboard') }}"
                        class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-500"
                    >
                        {{ __('Dashboard') }}
                    </a>
                @else
                    <a
                        href="{{ route('register') }}"
                        class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-500"
                    >
                        {{ __("I'm a landlord") }}
                    </a>
                @endauth
            </nav>
        </header>

        <main>
            {{ $slot }}
        </main>

        <footer class="mx-auto w-full max-w-5xl px-6 py-10 text-center text-sm text-zinc-400 sm:px-8">
            &copy; {{ date('Y') }} {{ config('app.name') }}
            @guest
                &middot;
                <a href="{{ route('login') }}" class="underline transition-colors hover:text-white">{{ __('Landlord or tenant log in') }}</a>
            @endguest
        </footer>

        @fluxAppearance
    </body>
</html>
