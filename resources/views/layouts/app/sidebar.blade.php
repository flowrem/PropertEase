<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ auth()->user()->is_super_admin ? route('admin.dashboard') : route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @unless (auth()->user()->is_super_admin)
                <livewire:team-switcher />
            @endunless

            <flux:sidebar.nav>
                @if (auth()->user()->is_super_admin)
                    <flux:sidebar.group :heading="__('Admin')" class="grid">
                        <flux:sidebar.item icon="squares-2x2" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>
                            {{ __('Overview') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="building-office-2" :href="route('admin.landlords')" :current="request()->routeIs('admin.landlords')" wire:navigate>
                            {{ __('Landlords') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="home-modern" :href="route('admin.listings')" :current="request()->routeIs('admin.listings')" wire:navigate>
                            {{ __('Listings') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @else
                    @php($isLandlord = auth()->user()->isLandlordOn(auth()->user()->currentTeam))

                    <flux:sidebar.group :heading="__('Platform')" class="grid">
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Home') }}
                        </flux:sidebar.item>

                        @if ($isLandlord)
                            <flux:sidebar.item icon="building-office-2" :href="route('properties')" :current="request()->routeIs('properties')" wire:navigate>
                                {{ __('Properties') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="users" :href="route('tenants')" :current="request()->routeIs('tenants')" wire:navigate>
                                {{ __('Tenants') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="home-modern" :href="route('listings')" :current="request()->routeIs('listings')" wire:navigate>
                                {{ __('Listings') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item
                                icon="clipboard-document-check"
                                :href="route('reservations')"
                                :current="request()->routeIs('reservations')"
                                :badge="\App\Models\Reservation::where('team_id', auth()->user()->current_team_id)->pending()->count() ?: null"
                                wire:navigate
                            >
                                {{ __('Reservations') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="banknotes" :href="route('invoices')" :current="request()->routeIs('invoices')" wire:navigate>
                                {{ __('Invoices') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="qr-code" :href="route('payment-settings')" :current="request()->routeIs('payment-settings')" wire:navigate>
                                {{ __('Payment settings') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="wrench-screwdriver" :href="route('landlord.maintenance')" :current="request()->routeIs('landlord.maintenance')" wire:navigate>
                                {{ __('Maintenance') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="flag" :href="route('landlord.complaints')" :current="request()->routeIs('landlord.complaints')" wire:navigate>
                                {{ __('Complaints') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="megaphone" :href="route('announcements')" :current="request()->routeIs('announcements')" wire:navigate>
                                {{ __('Announcements') }}
                            </flux:sidebar.item>
                        @else
                            <flux:sidebar.item icon="banknotes" :href="route('billing')" :current="request()->routeIs('billing')" wire:navigate>
                                {{ __('Billing') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="wrench-screwdriver" :href="route('maintenance')" :current="request()->routeIs('maintenance')" wire:navigate>
                                {{ __('Maintenance') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="flag" :href="route('complaints')" :current="request()->routeIs('complaints')" wire:navigate>
                                {{ __('Complaints') }}
                            </flux:sidebar.item>
                            <flux:sidebar.item icon="megaphone" :href="route('announcements')" :current="request()->routeIs('announcements')" wire:navigate>
                                {{ __('Announcements') }}
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
