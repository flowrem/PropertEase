@props(['title', 'description' => null, 'points', 'unit' => '', 'prefix' => '', 'period' => 'Week', 'valueLabel' => null, 'emptyText' => null])

@php
    $peak = max(1, (int) ceil(collect($points)->max('value') ?? 0));
    // Clean axis tops (2, 4, 10, 20, 40, 100, ...) whose midpoint is always a whole number.
    $ceiling = collect(range(0, strlen((string) $peak)))
        ->flatMap(fn ($power) => [2 * 10 ** $power, 4 * 10 ** $power, 10 * 10 ** $power])
        ->first(fn ($candidate) => $candidate >= $peak);
    $last = count($points) - 1;
    $valueLabel ??= ucfirst($unit ?: __('Amount'));
    $isEmpty = (float) collect($points)->sum('value') === 0.0;
    $tick = fn ($value) => $prefix.\Illuminate\Support\Number::abbreviate($value);
@endphp

<div {{ $attributes->class('rounded-lg border border-zinc-200 p-5 dark:border-zinc-700') }}>
    <flux:heading size="sm">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
    @endif

    @if ($isEmpty)
        <flux:text class="mt-5 text-zinc-500 dark:text-zinc-400">{{ $emptyText ?? __('Nothing recorded in this period.') }}</flux:text>
    @else
    <div class="mt-5 flex gap-3">
        <div class="flex h-40 w-14 shrink-0 flex-col justify-between text-right text-xs tabular-nums text-zinc-500 dark:text-zinc-400" aria-hidden="true">
            <span>{{ $tick($ceiling) }}</span>
            <span>{{ $tick($ceiling / 2) }}</span>
            <span>{{ $prefix }}0</span>
        </div>

        <div class="relative h-40 flex-1">
            <div class="absolute inset-x-0 top-0 border-t border-zinc-200 dark:border-zinc-700" aria-hidden="true"></div>
            <div class="absolute inset-x-0 top-1/2 border-t border-zinc-200 dark:border-zinc-700" aria-hidden="true"></div>
            <div class="absolute inset-x-0 bottom-0 border-t border-zinc-300 dark:border-zinc-600" aria-hidden="true"></div>

            <div class="relative flex h-full items-end gap-1">
                @foreach ($points as $index => $point)
                    @php($height = min(100, round($point['value'] / $ceiling * 100, 2)))

                    <div
                        class="group relative flex h-full flex-1 items-end justify-center"
                        title="{{ $point['hint'] }}: {{ $prefix }}{{ number_format($point['value']) }} {{ $unit }}"
                    >
                        <div
                            class="w-full max-w-6 rounded-t-[4px] bg-brand-500 transition-opacity group-hover:opacity-75"
                            style="height: {{ $height }}%"
                        ></div>

                        @if ($index === $last)
                            <span
                                class="absolute text-xs font-medium tabular-nums text-zinc-700 dark:text-zinc-200"
                                style="bottom: calc({{ $height }}% + 4px)"
                            >{{ $prefix }}{{ number_format($point['value']) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-2 flex gap-3 text-xs text-zinc-500 dark:text-zinc-400" aria-hidden="true">
        <div class="w-14 shrink-0"></div>
        <div class="flex flex-1 justify-between">
            <span>{{ $points[0]['label'] }}</span>
            <span>{{ $points[$last]['label'] }}</span>
        </div>
    </div>

    <details class="mt-4 text-sm">
        <summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">{{ __('View as table') }}</summary>
        <table class="mt-2 w-full text-left">
            <thead>
                <tr class="text-zinc-500 dark:text-zinc-400">
                    <th class="py-1 font-medium">{{ __($period) }}</th>
                    <th class="py-1 text-right font-medium">{{ $valueLabel }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($points as $point)
                    <tr class="border-t border-zinc-200 dark:border-zinc-700">
                        <td class="py-1">{{ $point['hint'] }}</td>
                        <td class="py-1 text-right tabular-nums">{{ $prefix }}{{ number_format($point['value'], $prefix ? 2 : 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
    @endif
</div>
