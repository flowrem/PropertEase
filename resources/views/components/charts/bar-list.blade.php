@props(['title', 'description' => null, 'rows'])

@php($peak = max(1, (int) collect($rows)->max('value')))

<div {{ $attributes->class('rounded-lg border border-zinc-200 p-5 dark:border-zinc-700') }}>
    <flux:heading size="sm">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
    @endif

    <div class="mt-5 space-y-3">
        @foreach ($rows as $row)
            <div class="flex items-center gap-3" title="{{ $row['label'] }}: {{ $row['value'] }}">
                <span class="w-28 shrink-0 text-sm text-zinc-700 dark:text-zinc-200">{{ $row['label'] }}</span>

                <div class="flex-1">
                    <div
                        @class([
                            'h-6 rounded-r-[4px]',
                            'bg-brand-500' => $row['emphasis'] ?? false,
                            'bg-zinc-300 dark:bg-zinc-600' => ! ($row['emphasis'] ?? false),
                        ])
                        style="width: {{ round($row['value'] / $peak * 100, 2) }}%"
                    ></div>
                </div>

                <span class="w-8 shrink-0 text-right text-sm font-medium tabular-nums">{{ number_format($row['value']) }}</span>
            </div>
        @endforeach
    </div>
</div>
