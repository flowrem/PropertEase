<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex">
        <title>{{ __('Rental Agreement') }} &middot; {{ config('app.name') }}</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-white text-zinc-900">
        <main class="mx-auto max-w-3xl px-6 py-8 print:max-w-none print:p-0">
            <div class="mb-6 flex items-center justify-between gap-4 print:hidden">
                <p class="text-sm text-zinc-600">{{ __('Use your browser\'s Print, then choose "Save as PDF" to keep a copy.') }}</p>
                <button type="button" onclick="window.print()" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-500">
                    {{ __('Print') }}
                </button>
            </div>

            {!! $contract->body_html !!}

            <p class="mt-6 text-xs text-zinc-600">
                @if ($contract->isAccepted())
                    {{ __(':name agreed to this contract in Occuplace on :date.', [
                        'name' => $contract->lease->tenant->name,
                        'date' => $contract->tenant_accepted_at->timezone(config('occuplace.display_timezone'))->format('M j, Y, g:i A'),
                    ]) }}
                @else
                    {{ __('The tenant has not agreed to this contract in Occuplace yet.') }}
                @endif
            </p>
        </main>
    </body>
</html>
