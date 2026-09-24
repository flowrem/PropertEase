@props([
    'concerns',
    'showPriority' => false,
    'emptyTitle',
    'emptyText',
])

@forelse ($concerns as $concern)
    <div wire:key="concern-{{ $concern->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($showPriority)
                        <flux:badge :color="$concern->priority->color()" size="sm">
                            {{ $concern->priority->label() }}
                        </flux:badge>
                    @endif
                    <flux:badge :color="$concern->status->color()" size="sm">
                        {{ $concern->status->label() }}
                    </flux:badge>
                </div>
                <flux:heading size="sm" class="mt-2">{{ $concern->title }}</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400">
                    {{ $concern->lease->tenant->name }} &middot; {{ __('Unit :number', ['number' => $concern->lease->unit->unit_number]) }}
                </flux:text>
            </div>
            <flux:text class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $concern->created_at->diffForHumans() }}</flux:text>
        </div>
    </div>
@empty
    <div class="rounded-xl border border-zinc-200 p-10 text-center dark:border-zinc-700">
        <flux:heading>{{ $emptyTitle }}</flux:heading>
        <flux:subheading>{{ $emptyText }}</flux:subheading>
    </div>
@endforelse
