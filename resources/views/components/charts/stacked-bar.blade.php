@props(['title', 'description' => null, 'segments'])

@php($total = (int) collect($segments)->sum('value'))

<div {{ $attributes->class('rounded-lg border border-zinc-200 p-5 dark:border-zinc-700') }}>
    <flux:heading size="sm">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
    @endif

    @if ($total === 0)
        <flux:text class="mt-5 text-zinc-500 dark:text-zinc-400">{{ __('Nothing to show yet.') }}</flux:text>
    @else
        <div class="mt-5 flex h-6 gap-0.5" role="img" aria-label="{{ collect($segments)->map(fn ($segment) => $segment['label'].' '.$segment['value'])->implode(', ') }}">
            @foreach ($segments as $segment)
                @if ($segment['value'] > 0)
                    <div
                        class="{{ $segment['color'] }} first:rounded-l-[4px] last:rounded-r-[4px]"
                        style="flex-grow: {{ $segment['value'] }}"
                        title="{{ $segment['label'] }}: {{ $segment['value'] }}"
                    ></div>
                @endif
            @endforeach
        </div>

        <ul class="mt-4 space-y-2">
            @foreach ($segments as $segment)
                <li class="flex items-center gap-2 text-sm">
                    <span class="{{ $segment['color'] }} size-3 shrink-0 rounded-sm" aria-hidden="true"></span>
                    <flux:icon :name="$segment['icon']" variant="mini" class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400" />
                    <span class="flex-1 text-zinc-700 dark:text-zinc-200">{{ $segment['label'] }}</span>
                    <span class="font-medium tabular-nums">{{ number_format($segment['value']) }}</span>
                    <span class="w-10 text-right text-zinc-500 tabular-nums dark:text-zinc-400">{{ round($segment['value'] / $total * 100) }}%</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
