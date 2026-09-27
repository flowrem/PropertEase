@props([
    'floorLevel',
    'floorArea',
    'bedrooms',
    'bathrooms',
])

<flux:modal name="confirm-unit-modal" class="max-w-md md:min-w-md" @close="closeConfirmUnitModal" wire:model="showConfirmUnitModal">
    <div class="space-y-6">
        <div class="space-y-2">
            <flux:heading size="lg">{{ __('Check these before saving') }}</flux:heading>
            <flux:text>{{ __('These details cannot be changed once the unit is saved.') }}</flux:text>
        </div>

        <dl class="grid grid-cols-2 gap-3 text-sm">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Floor') }}</dt>
                <dd class="font-medium">{{ $floorLevel }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Floor area') }}</dt>
                <dd class="font-medium">{{ is_numeric($floorArea) ? \App\Models\Unit::formatFloorArea((float) $floorArea) : '' }} m²</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Bedrooms') }}</dt>
                <dd class="font-medium">{{ (int) $bedrooms === 0 ? __('Studio') : $bedrooms }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Bathrooms') }}</dt>
                <dd class="font-medium">{{ (int) $bathrooms === 0 ? __('Shared') : $bathrooms }}</dd>
            </div>
        </dl>

        <div class="flex justify-end gap-3">
            <flux:button variant="outline" wire:click="closeConfirmUnitModal">{{ __('Go back') }}</flux:button>
            <flux:button variant="primary" wire:click="addUnit">{{ __('Save unit') }}</flux:button>
        </div>
    </div>
</flux:modal>
