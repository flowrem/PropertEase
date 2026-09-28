{{--
    A lease contract, rendered once from the snapshot in $terms and stored as
    HTML, so it reads the same later whatever changes. Every value is escaped
    here; the stored HTML is only ever this template's output.
--}}
@php
    $peso = fn (float|int $amount): string => '₱'.number_format((float) $amount, 2);
    $unit = $terms['unit'];
    $rooms = collect([
        $unit['bedrooms'] === 0 ? __('studio') : trans_choice(':count bedroom|:count bedrooms', $unit['bedrooms']),
        $unit['bathrooms'] === 0 ? __('shared bathroom') : trans_choice(':count bathroom|:count bathrooms', $unit['bathrooms']),
    ])->implode(', ');
@endphp

<article class="contract-document space-y-6 text-sm leading-relaxed text-zinc-800">
    <header class="text-center">
        <h1 class="text-xl font-semibold text-zinc-900">{{ __('Rental Agreement') }}</h1>
        <p class="text-zinc-600">{{ __('Made on :date', ['date' => $terms['generated_on']]) }}</p>
    </header>

    <section>
        <h2 class="mb-1 font-semibold text-zinc-900">{{ __('1. The parties') }}</h2>
        <p>
            <strong>{{ __('Landlord:') }}</strong>
            {{ $terms['landlord']['business'] }}@if ($terms['landlord']['name']), {{ __('represented by :name', ['name' => $terms['landlord']['name']]) }}@endif.
            @if ($terms['landlord']['email']) {{ $terms['landlord']['email'] }}@endif
            @if ($terms['landlord']['contact_number']) &middot; {{ $terms['landlord']['contact_number'] }}@endif
        </p>
        <p>
            <strong>{{ __('Tenant:') }}</strong>
            {{ $terms['tenant']['name'] }}. {{ $terms['tenant']['email'] }}
            @if ($terms['tenant']['contact_number']) &middot; {{ $terms['tenant']['contact_number'] }}@endif
        </p>
    </section>

    <section>
        <h2 class="mb-1 font-semibold text-zinc-900">{{ __('2. The unit') }}</h2>
        <p>
            {{ __('Unit :unit of :property (:type), :address.', ['unit' => $unit['name'], 'property' => $terms['property']['name'], 'type' => $terms['property']['type'], 'address' => $terms['property']['address']]) }}
            @if ($unit['floor']) {{ __('Floor: :floor.', ['floor' => $unit['floor']]) }}@endif
            @if ($unit['floor_area']) {{ __('Floor area: :area m².', ['area' => $unit['floor_area']]) }}@endif
            {{ __('Rooms: :rooms.', ['rooms' => $rooms]) }}
        </p>
    </section>

    <section>
        <h2 class="mb-1 font-semibold text-zinc-900">{{ __('3. Term') }}</h2>
        <p>
            {{ __('The lease starts on :date.', ['date' => $terms['start_date']]) }}
            @if ($terms['stay']['type'])
                {{ trans_choice('This is a :type stay with a minimum of :count month.|This is a :type stay with a minimum of :count months.', (int) $terms['stay']['minimum_months'], ['type' => Str::lower($terms['stay']['type'])]) }}
            @endif
            {{ trans_choice('Either party gives :count day of notice before the tenant moves out.|Either party gives :count days of notice before the tenant moves out.', $terms['notice_days']) }}
        </p>
    </section>

    <section>
        <h2 class="mb-1 font-semibold text-zinc-900">{{ __('4. Rent and payments') }}</h2>
        <ul class="list-disc space-y-1 ps-5">
            <li>{{ __('Monthly rent: :amount, due on day :day of each month, paid in :timing.', ['amount' => $peso($terms['rent']), 'day' => $terms['due_day'], 'timing' => Str::lower($terms['billing_timing'])]) }}</li>
            @if ($terms['advance']['months'] > 0)
                <li>{{ trans_choice('Advance: :count month of rent (:amount), paid before moving in.|Advance: :count months of rent (:amount), paid before moving in.', $terms['advance']['months'], ['amount' => $peso($terms['advance']['amount'])]) }}</li>
            @endif
            @if ($terms['deposit']['months'] > 0)
                <li>{{ trans_choice('Deposit: :count month of rent (:amount), returned at move-out less any unpaid rent or damage beyond normal wear.|Deposit: :count months of rent (:amount), returned at move-out less any unpaid rent or damage beyond normal wear.', $terms['deposit']['months'], ['amount' => $peso($terms['deposit']['amount'])]) }}</li>
            @endif
            <li>
                @if ($terms['late_fee'] !== null)
                    {{ __('Late payment fee: :amount for a payment made after its due date.', ['amount' => $peso($terms['late_fee'])]) }}
                @else
                    {{ __('No late payment fee.') }}
                @endif
            </li>
        </ul>
    </section>

    <section>
        <h2 class="mb-1 font-semibold text-zinc-900">{{ __('5. What the unit comes with') }}</h2>
        @if ($terms['amenities'] === [])
            <p>{{ __('No furniture or appliances are listed for this unit.') }}</p>
        @else
            <p>{{ implode(', ', $terms['amenities']) }}.</p>
        @endif
    </section>

    <section>
        <h2 class="mb-1 font-semibold text-zinc-900">{{ __('6. Condition at move-in') }}</h2>
        @php($check = $terms['move_in_check'])
        @if ($check === null)
            <p>{{ __('No move-in check was recorded.') }}</p>
        @else
            @if ($check['date'])
                <p>
                    {{ trans_choice('A move-in check of :count item was recorded on :date.|A move-in check of :count items was recorded on :date.', $check['item_count'], ['date' => $check['date']]) }}
                    {{ $check['issues'] === [] ? __('Everything was in good or working condition.') : __('These were not in working order:') }}
                </p>
                @if ($check['issues'] !== [])
                    <ul class="list-disc ps-5">
                        @foreach ($check['issues'] as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                @endif
            @endif
            @if ($check['override_reason'])
                <p>{{ __('The landlord let the tenant move in without a clean check: :reason', ['reason' => $check['override_reason']]) }}</p>
            @endif
        @endif
    </section>

    @if ($terms['house_rules'])
        <section>
            <h2 class="mb-1 font-semibold text-zinc-900">{{ __('7. House rules') }}</h2>
            <p class="whitespace-pre-line">{{ $terms['house_rules'] }}</p>
        </section>
    @endif

    @if ($terms['additional_terms'])
        <section>
            <h2 class="mb-1 font-semibold text-zinc-900">{{ $terms['house_rules'] ? __('8. Other terms') : __('7. Other terms') }}</h2>
            <p class="whitespace-pre-line">{{ $terms['additional_terms'] }}</p>
        </section>
    @endif

    <section class="border-t border-zinc-300 pt-4">
        <p>{{ __('The tenant agrees to this contract by clicking "I agree" in Occuplace, which records the date and time. A printed copy can also be signed below.') }}</p>
        <div class="mt-8 grid grid-cols-2 gap-8">
            <div>
                <div class="border-b border-zinc-400 pb-8"></div>
                <p class="mt-1 text-xs text-zinc-600">{{ __('Landlord') }}</p>
            </div>
            <div>
                <div class="border-b border-zinc-400 pb-8"></div>
                <p class="mt-1 text-xs text-zinc-600">{{ __('Tenant') }}</p>
            </div>
        </div>
    </section>
</article>
