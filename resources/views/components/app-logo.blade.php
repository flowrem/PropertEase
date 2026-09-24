@props([
    'sidebar' => false,
])

{{-- The mark is pale mint, so it always sits on a navy square: it stays readable on a light sidebar too. --}}
@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-brand-900">
            <x-app-logo-icon class="size-6 object-contain" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-brand-900">
            <x-app-logo-icon class="size-6 object-contain" />
        </x-slot>
    </flux:brand>
@endif
