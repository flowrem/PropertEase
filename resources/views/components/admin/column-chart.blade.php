@props(['title', 'description' => null, 'unit', 'points'])

@php
    $peak = max(1, (int) collect($points)->max('value'));
    // Clean axis tops (2, 4, 10, 20, 40, 100, ...) whose midpoint is always a whole number.
    $ceiling = collect(range(0, strlen((string) $peak)))
        ->flatMap(fn ($power) => [2 * 10 ** $power, 4 * 10 ** $power, 10 * 10 ** $power])
        ->first(fn ($candidate) => $candidate >= $peak);
    $last = count($points) - 1;
@endphp

<div {{ $attributes->class('rounded-lg border border-zinc-200 p-5 dark:border-zinc-700') }}>
    <flux:heading size="sm">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
    @endif

    <div class="mt-5 flex gap-3">
        <div class="flex h-40 flex-col justify-between text-right text-xs tabular-nums text-zinc-500 dark:text-zinc-400" aria-hidden="true">
            <span>{{ number_format($ceiling) }}</span>
            <span>{{ number_format($ceiling / 2) }}</span>
            <span>0</span>
        </div>

        <div class="relative h-40 flex-1">
            <div class="absolute inset-x-0 top-0 border-t border-zinc-200 dark:border-zinc-700" aria-hidden="true"></div>
            <div class="absolute inset-x-0 top-1/2 border-t border-zinc-200 dark:border-zinc-700" aria-hidden="true"></div>
            <div class="absolute inset-x-0 bottom-0 border-t border-zinc-300 dark:border-zinc-600" aria-hidden="true"></div>

            <div class="relative flex h-full items-end gap-1">
                @foreach ($points as $index => $point)
                    @php($height = round($point['value'] / $ceiling * 100, 2))

                    <div
                        class="group relative flex h-full flex-1 items-end justify-center"
                        title="{{ $point['hint'] }}: {{ $point['value'] }} {{ $unit }}"
                    >
                        <div
                            class="w-full max-w-6 rounded-t-[4px] bg-brand-500 transition-opacity group-hover:opacity-75"
                            style="height: {{ $height }}%"
                        ></div>

                        @if ($index === $last)
                            <span
                                class="absolute text-xs font-medium tabular-nums text-zinc-700 dark:text-zinc-200"
                                style="bottom: calc({{ $height }}% + 4px)"
                            >{{ number_format($point['value']) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-2 flex justify-between pl-9 text-xs text-zinc-500 dark:text-zinc-400" aria-hidden="true">
        <span>{{ $points[0]['label'] }}</span>
        <span>{{ $points[$last]['label'] }}</span>
    </div>

    <details class="mt-4 text-sm">
        <summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">{{ __('View as table') }}</summary>
        <table class="mt-2 w-full text-left">
            <thead>
                <tr class="text-zinc-500 dark:text-zinc-400">
                    <th class="py-1 font-medium">{{ __('Week') }}</th>
                    <th class="py-1 text-right font-medium">{{ ucfirst($unit) }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($points as $point)
                    <tr class="border-t border-zinc-200 dark:border-zinc-700">
                        <td class="py-1">{{ $point['hint'] }}</td>
                        <td class="py-1 text-right tabular-nums">{{ number_format($point['value']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </details>
</div>
