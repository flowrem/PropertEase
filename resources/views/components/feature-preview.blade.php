@props([
    'icon',
    'heading',
    'description',
    'items' => [],
])

<div class="flex flex-col gap-6">
    <div class="flex items-start gap-4">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800">
            <flux:icon :name="$icon" class="size-6 text-brand-500" />
        </span>

        <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
                <flux:badge color="zinc" size="sm">{{ __('Coming soon') }}</flux:badge>
            </div>
            <flux:subheading>{{ $description }}</flux:subheading>
        </div>
    </div>

    @if (count($items))
        <ul class="grid gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-6 sm:grid-cols-2 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach ($items as $item)
                <li class="flex items-start gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <flux:icon name="check" class="mt-0.5 size-4 shrink-0 text-brand-500" />
                    <span>{{ $item }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
