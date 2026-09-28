@props([
    'unit',
    'current',
])

{{-- Title and tabs shared by every page about one unit. --}}
<div class="flex flex-col gap-3">
    <div>
        <flux:link :href="route('properties')" wire:navigate class="text-sm">&larr; {{ __('Properties') }}</flux:link>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1">{{ __('Unit :number', ['number' => $unit->unit_number]) }}</flux:heading>
            <flux:badge :color="$unit->status->color()" size="sm">{{ $unit->status->label() }}</flux:badge>
        </div>
        <flux:subheading>{{ $unit->property->name }} &middot; {{ $unit->property->type->label() }}</flux:subheading>
    </div>

    <flux:navbar class="-mb-px border-b border-zinc-200 dark:border-zinc-700" scrollable>
        <flux:navbar.item :href="route('units.show', ['unit' => $unit])" :current="$current === 'overview'" wire:navigate>
            {{ __('Overview') }}
        </flux:navbar.item>
        <flux:navbar.item :href="route('units.edit', ['unit' => $unit])" :current="$current === 'details'" wire:navigate>
            {{ __('Details') }}
        </flux:navbar.item>
        <flux:navbar.item :href="route('units.inventory', ['unit' => $unit])" :current="$current === 'inventory'" wire:navigate>
            {{ __('Inventory & checks') }}
        </flux:navbar.item>
    </flux:navbar>
</div>
