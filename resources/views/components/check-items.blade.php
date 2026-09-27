@props([
    'check',
])

{{-- The items of one condition check with the condition each was found in. --}}
<ul {{ $attributes->class('divide-y divide-zinc-200') }}>
    @foreach ($check->items as $checkedItem)
        <li class="flex flex-wrap items-center justify-between gap-2 py-2">
            <div>
                <span class="text-sm font-medium">{{ $checkedItem->unitItem->name }}</span>
                @if ($checkedItem->remarks)
                    <flux:text class="text-xs">{{ $checkedItem->remarks }}</flux:text>
                @endif
            </div>
            <flux:badge size="sm" :color="$checkedItem->condition->color()">{{ $checkedItem->condition->label() }}</flux:badge>
        </li>
    @endforeach
</ul>

@if ($check->notes)
    <flux:text class="mt-3">{{ $check->notes }}</flux:text>
@endif
