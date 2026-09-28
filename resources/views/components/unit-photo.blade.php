@props([
    'unit',
])

{{-- The landlord's photo of the unit, or a plain placeholder when there is none. --}}
@if ($url = $unit->photoUrl())
    <img
        src="{{ $url }}"
        alt="{{ __('Photo of unit :number', ['number' => $unit->unit_number]) }}"
        loading="lazy"
        decoding="async"
        {{ $attributes->class('aspect-[4/3] w-full object-cover') }}
    >
@else
    <div {{ $attributes->class('flex aspect-[4/3] w-full items-center justify-center bg-sand/40') }} role="img" aria-label="{{ __('No photo of unit :number yet', ['number' => $unit->unit_number]) }}">
        <flux:icon.home class="size-10 text-brand-500/60" />
    </div>
@endif
