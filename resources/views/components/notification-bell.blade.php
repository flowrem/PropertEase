{{-- A link to the notifications page with the current team's unread count. Hidden for
     the Super Admin and for anyone without a team, since notifications belong to a team. --}}
@php
    $user = auth()->user();
    $team = $user?->currentTeam;
@endphp

@if ($user && $team && ! $user->is_super_admin)
    @php($unread = $user->unreadNotificationCountFor($team))

    <a
        href="{{ route('notifications') }}"
        wire:navigate
        {{ $attributes->class('relative inline-flex size-9 items-center justify-center rounded-lg text-zinc-600 transition-colors hover:bg-white hover:text-brand-600') }}
        aria-label="{{ $unread > 0 ? trans_choice(':count unread notification|:count unread notifications', $unread) : __('Notifications') }}"
    >
        <flux:icon.bell class="size-5" />

        @if ($unread > 0)
            <span class="absolute -end-0.5 -top-0.5 min-w-5 rounded-full bg-brand-600 px-1 text-center text-[11px] leading-5 font-semibold text-white">
                {{ $unread > 99 ? '99+' : $unread }}
            </span>
        @endif
    </a>
@endif
