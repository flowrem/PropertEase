@props([
    'title',
    'description',
])

{{-- Left-aligned so the heading lines up with the field labels below it. --}}
<div class="flex w-full flex-col gap-1">
    <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
    <flux:subheading>{{ $description }}</flux:subheading>
</div>
