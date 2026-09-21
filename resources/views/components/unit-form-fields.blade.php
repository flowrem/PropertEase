@props([
    'occupancy',
    'examples' => false,
])

<div class="grid gap-4 sm:grid-cols-2">
    <flux:input wire:model="unit_number" :label="__('Unit number')" :placeholder="$examples ? '101' : null" required autofocus />
    <flux:input wire:model="floor_level" :label="__('Floor level')" :placeholder="$examples ? '1st floor' : null" />
</div>

<div class="grid gap-4 sm:grid-cols-2">
    <flux:input wire:model="bedrooms" type="number" min="0" max="20" :label="__('Bedrooms')" required />
    <flux:input wire:model="bathrooms" type="number" min="0" max="20" :label="__('Bathrooms')" required />
</div>

<flux:input
    wire:model="price"
    type="number"
    step="0.01"
    min="0"
    :label="__('Monthly rent (₱)')"
    :placeholder="$examples ? '5000' : null"
    required
/>

<flux:radio.group wire:model.live="occupancy" :label="__('Occupancy')" variant="segmented">
    <flux:radio value="single">{{ __('Single tenant') }}</flux:radio>
    <flux:radio value="multiple">{{ __('Multiple tenants') }}</flux:radio>
</flux:radio.group>

@if ($occupancy === 'multiple')
    <flux:input wire:model="tenant_limit" type="number" min="2" max="50" :label="__('Maximum tenants')" required />

    <flux:text class="text-zinc-500 dark:text-zinc-400">
        {{ __('Rent is split equally among all current tenants.') }}
    </flux:text>
@endif
