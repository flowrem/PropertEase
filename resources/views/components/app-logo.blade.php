@props([
    'sidebar' => false,
])

{{-- The mark is pale mint. On the light theme it sits on a navy square so it stays readable;
     on the dark theme it sits straight on the page, so it matches whatever the background is. --}}
@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-brand-900 dark:bg-transparent">
            <x-app-logo-icon class="size-6 object-contain dark:size-8" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-brand-900 dark:bg-transparent">
            <x-app-logo-icon class="size-6 object-contain dark:size-8" />
        </x-slot>
    </flux:brand>
@endif
