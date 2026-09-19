@props([
    'icon',
    'title',
    'description',
    'href',
])

<a
    href="{{ $href }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'group flex items-start gap-4 rounded-xl border border-zinc-200 bg-white p-5 transition-colors hover:border-brand-500 dark:border-zinc-700 dark:bg-zinc-900']) }}
>
    <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
        <flux:icon :name="$icon" class="size-6 text-brand-500" />
    </span>

    <div class="flex flex-col gap-1">
        <flux:heading>{{ $title }}</flux:heading>
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
    </div>

    <flux:icon name="chevron-right" class="ms-auto mt-2 size-4 shrink-0 text-zinc-400 transition-transform group-hover:translate-x-0.5 group-hover:text-brand-500" />
</a>
