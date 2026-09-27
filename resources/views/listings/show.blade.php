@php
    $unit = $listing->unit;
    $property = $unit->property;
    $slots = $unit->slotsAvailable();
    $isShared = $unit->allows_multiple_tenants && $unit->tenant_limit !== null;
@endphp

<x-public-layout :title="$listing->title">
    <article class="mx-auto w-full max-w-5xl px-6 pb-16 sm:px-8">
        <a href="{{ route('listings.index') }}" class="text-sm text-zinc-600 transition-colors hover:text-zinc-900">&larr; {{ __('All listings') }}</a>

        <h1 class="mt-4 text-3xl font-semibold tracking-tight text-zinc-900">{{ $listing->title }}</h1>
        <p class="mt-1 text-zinc-600">
            {{ $property->name }} &middot; {{ $property->type->label() }} &middot; {{ __('Unit :number', ['number' => $unit->unit_number]) }}
        </p>

        @if ($listing->photos->isNotEmpty())
            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                @foreach ($listing->photos as $photo)
                    <img
                        src="{{ $photo->url() }}"
                        alt="{{ $listing->title }} ({{ $loop->iteration }})"
                        @if (! $loop->first) loading="lazy" @endif
                        width="800"
                        height="600"
                        class="aspect-[4/3] w-full rounded-xl object-cover {{ $loop->first ? 'sm:col-span-2 sm:aspect-video' : '' }}"
                    >
                @endforeach
            </div>
        @endif

        <div class="mt-8 grid gap-8 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <h2 class="font-semibold text-zinc-900">{{ __('About this place') }}</h2>
                <p class="mt-2 whitespace-pre-line text-zinc-700">{{ $listing->description }}</p>

                @if ($unit->amenities->isNotEmpty())
                    <h2 class="mt-8 font-semibold text-zinc-900">{{ __("What's included") }}</h2>
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        @foreach (\App\Models\Amenity::groupByCategory($unit->amenities) as $category => $amenities)
                            <div>
                                <h3 class="text-xs font-medium uppercase tracking-wide text-zinc-600">{{ $category }}</h3>
                                <ul class="mt-1 space-y-1 text-zinc-700">
                                    @foreach ($amenities as $amenity)
                                        <li>
                                            {{ $amenity->name }}
                                            @if ($amenity->pivot->quantity > 1)
                                                <span class="text-zinc-600">&times; {{ $amenity->pivot->quantity }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif

                <h2 class="mt-8 font-semibold text-zinc-900">{{ __('Location') }}</h2>
                <p class="mt-2 text-zinc-700">
                    {{ $property->address_line }}, {{ $property->city }}, {{ $property->province }} {{ $property->postal_code }}
                </p>
                @if ($property->map_url)
                    <a href="{{ $property->map_url }}" target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-sm text-brand-500 underline">
                        {{ __('Open in maps') }}
                    </a>
                @endif
            </div>

            <aside class="flex flex-col gap-5 rounded-xl border border-sand bg-white p-6">
                <div>
                    <p class="text-2xl font-semibold text-zinc-900">&#8369;{{ number_format((float) $unit->price, 2) }}<span class="text-sm font-normal text-zinc-600"> / {{ __('month') }}</span></p>
                    @if ($isShared)
                        <p class="mt-1 text-sm text-zinc-600">{{ __('Shared unit: the rent is split equally among its tenants.') }}</p>
                    @endif
                </div>

                <dl class="flex flex-col gap-3 text-sm">
                    <div>
                        <dt class="text-zinc-600">{{ __('Availability') }}</dt>
                        <dd class="text-zinc-900">
                            {{ trans_choice(':count slot available|:count slots available', $slots) }}
                            @if ($isShared)
                                ({{ __('up to :count tenants', ['count' => $unit->capacity()]) }})
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-zinc-600">{{ __('Downpayment to reserve') }}</dt>
                        <dd class="text-zinc-900">
                            {{ $listing->downpayment_amount !== null ? '₱'.number_format((float) $listing->downpayment_amount, 2) : __('Ask the landlord') }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-zinc-600">{{ __('Accepted payment methods') }}</dt>
                        <dd class="text-zinc-900">{{ $paymentMethods->isNotEmpty() ? $paymentMethods->implode(', ') : __('Not accepting online reservations right now') }}</dd>
                    </div>
                </dl>

                <div class="border-t border-zinc-300 pt-4 text-sm">
                    <p class="text-zinc-600">{{ __('Landlord contact') }}</p>
                    <p class="mt-1 text-zinc-900">{{ $listing->contact_name }}</p>
                    <p><a href="tel:{{ $listing->contact_phone }}" class="text-brand-500 underline">{{ $listing->contact_phone }}</a></p>
                    <p><a href="mailto:{{ $listing->contact_email }}" class="break-all text-brand-500 underline">{{ $listing->contact_email }}</a></p>
                </div>

                @if (Route::has('listings.reserve') && $paymentMethods->isNotEmpty())
                    <a
                        href="{{ route('listings.reserve', $listing) }}"
                        class="rounded-lg bg-brand-600 px-5 py-3 text-center text-sm font-medium text-white transition-colors hover:bg-brand-500"
                    >
                        {{ __('Reserve this unit') }}
                    </a>
                @endif
            </aside>
        </div>
    </article>
</x-public-layout>
