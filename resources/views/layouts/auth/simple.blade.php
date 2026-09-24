<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-brand-900 antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <a href="{{ route('home') }}" class="flex flex-col items-center" wire:navigate>
                <img
                    src="{{ asset('images/logo.png') }}"
                    alt="{{ config('app.name') }}"
                    width="520"
                    height="407"
                    class="h-28 w-auto"
                    decoding="async"
                >
            </a>

            <div class="flex w-full max-w-md flex-col gap-6">
                <div class="rounded-xl border border-brand-600/25 bg-brand-800 px-8 py-8 shadow-lg shadow-black/20 sm:px-10">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
