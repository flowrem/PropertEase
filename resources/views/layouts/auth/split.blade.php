<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen antialiased bg-white text-coastal-900 dark:bg-coastal-900 dark:text-coastal-100">
        <div class="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
            {{-- Brand panel: what PropertEase is for, stated plainly. Hidden below lg, where the form is all that matters. --}}
            <aside class="hidden flex-col justify-between p-12 bg-coastal-700 border-e border-coastal-600 lg:flex">
                <a href="{{ route('home') }}" class="flex items-center gap-3" wire:navigate>
                    <x-app-logo-icon class="size-8 text-coastal-200" />
                    <span class="text-lg font-semibold tracking-tight text-white">{{ config('app.name') }}</span>
                </a>

                <div class="max-w-md">
                    <h2 class="text-3xl font-semibold leading-tight text-white text-balance">
                        {{ __('A written record for every unit you manage.') }}
                    </h2>
                    <p class="mt-4 text-coastal-200">
                        {{ __('Rent, repairs and utility charges stop living in chat threads and start living somewhere both the landlord and the tenant can check.') }}
                    </p>

                    {{-- Each icon below names one thing the system records. Swap in the chosen Flaticon SVG per icon by
                         adding resources/views/flux/icon/<name>.blade.php and changing the name here. --}}
                    <ul class="mt-10 space-y-5 text-sm">
                        <li class="flex gap-3">
                            <flux:icon name="banknotes" class="mt-0.5 size-5 shrink-0 text-coastal-300" />
                            <span class="text-coastal-100">{{ __('Rent ledger with the running balance and the next due date') }}</span>
                        </li>
                        <li class="flex gap-3">
                            <flux:icon name="bolt" class="mt-0.5 size-5 shrink-0 text-coastal-300" />
                            <span class="text-coastal-100">{{ __('Water and electricity billed per unit, itemised on the statement') }}</span>
                        </li>
                        <li class="flex gap-3">
                            <flux:icon name="wrench-screwdriver" class="mt-0.5 size-5 shrink-0 text-coastal-300" />
                            <span class="text-coastal-100">{{ __('Repair requests and complaints logged with a date and an owner') }}</span>
                        </li>
                    </ul>
                </div>

                <p class="text-xs text-coastal-300">
                    &copy; {{ now()->year }} {{ config('app.name') }}
                </p>
            </aside>

            <main class="flex flex-col justify-center px-6 py-12 sm:px-10">
                <div class="mx-auto w-full max-w-sm">
                    <a href="{{ route('home') }}" class="mb-10 flex items-center gap-3 lg:hidden" wire:navigate>
                        <x-app-logo-icon class="size-7 text-coastal-600 dark:text-coastal-300" />
                        <span class="text-base font-semibold tracking-tight text-coastal-900 dark:text-white">{{ config('app.name') }}</span>
                    </a>

                    {{ $slot }}
                </div>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
