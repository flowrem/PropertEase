<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-brand-900 antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-brand-600">
                    <x-app-logo-icon class="size-6 fill-current text-white" />
                </span>

                <span class="text-sm font-semibold tracking-tight text-white">{{ config('app.name') }}</span>
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
