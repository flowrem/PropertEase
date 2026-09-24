<x-public-layout :title="__('Find a place')">
    <section class="mx-auto w-full max-w-5xl px-6 pb-16 sm:px-8">
        <h1 class="text-3xl font-semibold tracking-tight text-white">{{ __('Find an apartment or dorm') }}</h1>
        <p class="mt-2 text-zinc-400">{{ __('Approved listings with room available right now.') }}</p>

        <form method="GET" action="{{ route('listings.index') }}" class="mt-6 grid gap-3 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label for="q" class="mb-1 block text-sm text-zinc-300">{{ __('City or province') }}</label>
                <input
                    id="q"
                    name="q"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['q'] ?? '' }}"
                    class="w-full rounded-lg border border-zinc-700 bg-brand-800 px-3 py-2 text-sm text-white placeholder:text-zinc-400"
                >
            </div>

            <div>
                <label for="type" class="mb-1 block text-sm text-zinc-300">{{ __('Type') }}</label>
                <select id="type" name="type" class="w-full rounded-lg border border-zinc-700 bg-brand-800 px-3 py-2 text-sm text-white">
                    <option value="">{{ __('Any') }}</option>
                    @foreach (\App\Enums\PropertyType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="max_price" class="mb-1 block text-sm text-zinc-300">{{ __('Max price per month') }}</label>
                <input
                    id="max_price"
                    name="max_price"
                    type="number"
                    min="0"
                    step="100"
                    value="{{ $filters['max_price'] ?? '' }}"
                    class="w-full rounded-lg border border-zinc-700 bg-brand-800 px-3 py-2 text-sm text-white placeholder:text-zinc-400"
                >
            </div>

            <div class="flex gap-2 sm:col-span-4">
                <button type="submit" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-500">
                    {{ __('Search') }}
                </button>
                @if (! empty($filters))
                    <a href="{{ route('listings.index') }}" class="rounded-lg border border-zinc-700 px-5 py-2 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-500 hover:text-white">
                        {{ __('Clear') }}
                    </a>
                @endif
            </div>
        </form>

        @if ($listings->isEmpty())
            <div class="mt-10 rounded-xl border border-zinc-800 bg-brand-800 p-10 text-center">
                <h2 class="font-semibold text-white">{{ __('No listings match') }}</h2>
                <p class="mt-2 text-sm text-zinc-400">
                    {{ empty($filters) ? __('There are no listings with room available right now. Check back soon.') : __('Try a different place, type or price.') }}
                </p>
            </div>
        @else
            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($listings as $listing)
                    @php($unit = $listing->unit)
                    @php($property = $unit->property)
                    @php($slots = $unit->slotsAvailable())

                    <a
                        href="{{ route('listings.show', $listing) }}"
                        class="flex flex-col overflow-hidden rounded-xl border border-zinc-800 bg-brand-800 transition-colors hover:border-brand-500"
                    >
                        @if ($listing->photos->first())
                            <img
                                src="{{ $listing->photos->first()->url() }}"
                                alt="{{ $listing->title }}"
                                loading="lazy"
                                width="640"
                                height="360"
                                class="aspect-video w-full object-cover"
                            >
                        @else
                            <div class="aspect-video w-full bg-zinc-900"></div>
                        @endif

                        <div class="flex flex-1 flex-col gap-1 p-4">
                            <h2 class="font-semibold text-white">{{ $property->name }}</h2>
                            <p class="text-sm text-zinc-400">{{ $property->type->label() }} &middot; {{ $property->city }}, {{ $property->province }}</p>
                            <p class="mt-2 text-lg font-semibold text-white">&#8369;{{ number_format((float) $unit->price, 2) }}<span class="text-sm font-normal text-zinc-400"> / {{ __('month') }}</span></p>
                            <p class="text-sm text-zinc-400">{{ trans_choice(':count slot available|:count slots available', $slots) }}</p>
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-8">
                {{ $listings->links() }}
            </div>
        @endif
    </section>
</x-public-layout>
