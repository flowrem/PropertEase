<x-public-layout :title="__('Find a place')">
    <section class="mx-auto w-full max-w-5xl px-6 pb-16 sm:px-8">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900">{{ __('Find an apartment or dorm') }}</h1>
        <p class="mt-2 text-zinc-600">{{ __('Approved listings with room available right now.') }}</p>

        <form method="GET" action="{{ route('listings.index') }}" class="mt-6 grid gap-3 sm:grid-cols-4">
            <div>
                <label for="region" class="mb-1 block text-sm text-zinc-700">{{ __('Region') }}</label>
                {{-- Choosing a region reloads the page so the City list shows that region's cities; without JavaScript, Search does the same. --}}
                <select id="region" name="region" onchange="this.form.city.value = ''; this.form.submit()" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                    <option value="">{{ __('Anywhere') }}</option>
                    @foreach ($regions as $region)
                        <option value="{{ $region->code }}" @selected($regionCode === $region->code)>{{ $region->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="city" class="mb-1 block text-sm text-zinc-700">{{ __('City or municipality') }}</label>
                <select id="city" name="city" @disabled(! $regionCode) class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 disabled:bg-zinc-100 disabled:text-zinc-500">
                    <option value="">{{ $regionCode ? __('Any in this region') : __('Choose a region first') }}</option>
                    @foreach ($cityOptions as $city)
                        <option value="{{ $city->code }}" @selected($cityCode === $city->code)>{{ $city->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="type" class="mb-1 block text-sm text-zinc-700">{{ __('Type') }}</label>
                <select id="type" name="type" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900">
                    <option value="">{{ __('Any') }}</option>
                    @foreach (\App\Enums\PropertyType::cases() as $type)
                        <option value="{{ $type->value }}" @selected(($filters['type'] ?? null) === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="max_price" class="mb-1 block text-sm text-zinc-700">{{ __('Max price per month') }}</label>
                <input
                    id="max_price"
                    name="max_price"
                    type="number"
                    min="0"
                    step="100"
                    value="{{ $filters['max_price'] ?? '' }}"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-500"
                >
            </div>

            <div class="sm:col-span-4">
                <label for="q" class="mb-1 block text-sm text-zinc-700">{{ __('Or search a place name') }}</label>
                <input
                    id="q"
                    name="q"
                    type="search"
                    maxlength="100"
                    value="{{ $filters['q'] ?? '' }}"
                    placeholder="{{ __('Barangay, city or province') }}"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder:text-zinc-500"
                >
            </div>

            @php($selectedAmenities = array_map('intval', $filters['amenities'] ?? []))

            <details class="rounded-lg border border-zinc-300 bg-white px-4 py-3 sm:col-span-4" @if ($selectedAmenities !== []) open @endif>
                <summary class="cursor-pointer text-sm text-zinc-700">
                    {{ __('Amenities') }}
                    @if ($selectedAmenities !== [])
                        <span class="text-zinc-600">({{ count($selectedAmenities) }})</span>
                    @endif
                </summary>

                <div class="mt-3 grid gap-4 sm:grid-cols-3">
                    @foreach ($amenityFilters as $category => $amenities)
                        <fieldset>
                            <legend class="text-xs font-medium uppercase tracking-wide text-zinc-600">{{ $category }}</legend>
                            <div class="mt-2 space-y-1">
                                @foreach ($amenities as $amenity)
                                    <label class="flex items-center gap-2 text-sm text-zinc-800">
                                        <input
                                            type="checkbox"
                                            name="amenities[]"
                                            value="{{ $amenity->id }}"
                                            @checked(in_array($amenity->id, $selectedAmenities, true))
                                            class="rounded border-zinc-400 bg-brand-50"
                                        >
                                        {{ $amenity->name }}
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>
                    @endforeach
                </div>
            </details>

            <div class="flex gap-2 sm:col-span-4">
                <button type="submit" class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-500">
                    {{ __('Search') }}
                </button>
                @if (! empty($filters))
                    <a href="{{ route('listings.index') }}" class="rounded-lg border border-zinc-300 px-5 py-2 text-sm font-medium text-zinc-700 transition-colors hover:border-zinc-500 hover:text-zinc-900">
                        {{ __('Clear') }}
                    </a>
                @endif
            </div>
        </form>

        @if ($listings->isEmpty())
            <div class="mt-10 rounded-xl border border-sand bg-white p-10 text-center">
                <h2 class="font-semibold text-zinc-900">{{ __('No listings match') }}</h2>
                <p class="mt-2 text-sm text-zinc-600">
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
                        class="flex flex-col overflow-hidden rounded-xl border border-sand bg-white transition-colors hover:border-brand-500"
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
                            <div class="aspect-video w-full bg-zinc-100"></div>
                        @endif

                        <div class="flex flex-1 flex-col gap-1 p-4">
                            <h2 class="font-semibold text-zinc-900">{{ $property->name }}</h2>
                            <p class="text-sm text-zinc-600">{{ $property->type->label() }} &middot; {{ $property->city }}, {{ $property->province }}</p>
                            <p class="mt-2 text-lg font-semibold text-zinc-900">&#8369;{{ number_format((float) $unit->price, 2) }}<span class="text-sm font-normal text-zinc-600"> / {{ __('month') }}</span></p>
                            <p class="text-sm text-zinc-600">{{ trans_choice(':count slot available|:count slots available', $slots) }}</p>
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
