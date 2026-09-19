@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flex gap-2 rounded-lg border border-emerald-600/30 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300']) }}>
        <flux:icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
        <span>{{ $status }}</span>
    </div>
@endif
