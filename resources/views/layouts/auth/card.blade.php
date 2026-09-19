<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen antialiased bg-coastal-50 text-coastal-900 dark:bg-coastal-950 dark:text-coastal-100">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-8">
                <a href="{{ route('home') }}" class="flex items-center justify-center gap-3" wire:navigate>
                    <x-app-logo-icon class="size-7 text-coastal-600 dark:text-coastal-300" />
                    <span class="text-base font-semibold tracking-tight text-coastal-900 dark:text-white">{{ config('app.name') }}</span>
                </a>

                <div class="rounded-xl border border-coastal-200 bg-white shadow-sm dark:border-coastal-700 dark:bg-coastal-900 dark:shadow-lg dark:shadow-coastal-950/50">
                    <div class="px-10 py-8">{{ $slot }}</div>
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
