@props(['title', 'description' => null, 'value', 'total', 'caption', 'details' => [], 'emptyText' => null])

<div {{ $attributes->class('rounded-lg border border-zinc-200 p-5 dark:border-zinc-700') }}>
    <flux:heading size="sm">{{ $title }}</flux:heading>
    @if ($description)
        <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $description }}</flux:text>
    @endif

    @if ($total === 0)
        <flux:text class="mt-5 text-zinc-500 dark:text-zinc-400">{{ $emptyText ?? __('Nothing to show yet.') }}</flux:text>
    @else
        @php($share = round($value / $total * 100))

        <div class="mt-5 flex items-baseline gap-2">
            <span class="text-4xl font-semibold leading-none">{{ $share }}%</span>
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $caption }}</span>
        </div>

        <div
            class="mt-3 h-3 overflow-hidden rounded-full bg-brand-500/20"
            role="meter"
            aria-valuemin="0"
            aria-valuemax="{{ $total }}"
            aria-valuenow="{{ $value }}"
            aria-label="{{ $title }}"
        >
            <div class="h-full rounded-full bg-brand-500" style="width: {{ $share }}%"></div>
        </div>

        @if ($details)
            <dl class="mt-4 flex gap-6 text-sm">
                @foreach ($details as $label => $detail)
                    <div>
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                        <dd class="font-medium tabular-nums">{{ number_format($detail) }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    @endif
</div>
