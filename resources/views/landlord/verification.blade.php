<x-layouts::auth :title="__('Account review')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="$team->isRejected() ? __('We could not approve your account') : __('Your account is under review')"
            :description="$team->isRejected()
                ? __('Check the reason below and upload a new ID to try again.')
                : __(':app is checking your ID. You will get an email once a decision is made.', ['app' => config('app.name')])"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($team->isRejected())
            <flux:callout variant="danger" icon="x-circle">
                <flux:callout.heading>{{ __('Reason') }}</flux:callout.heading>
                <flux:callout.text>{{ $team->rejection_reason }}</flux:callout.text>
            </flux:callout>

            <form method="POST" action="{{ route('landlord.verification.resubmit') }}" enctype="multipart/form-data" class="flex flex-col gap-6">
                @csrf

                <flux:input
                    name="verification_id"
                    :label="__('New valid ID')"
                    type="file"
                    accept=".jpg,.jpeg,.png,.pdf"
                    required
                />

                <flux:field variant="inline">
                    <flux:checkbox name="consent" value="1" />
                    <flux:label>{{ __('I agree to share my ID with :app so it can confirm I am a real landlord.', ['app' => config('app.name')]) }}</flux:label>
                    <flux:error name="consent" />
                </flux:field>

                <flux:button variant="primary" type="submit" class="w-full">{{ __('Submit ID again') }}</flux:button>
            </form>
        @else
            <flux:text class="text-center text-zinc-500 dark:text-zinc-400">
                {{ __('Submitted :time.', ['time' => $team->verification_submitted_at?->diffForHumans()]) }}
            </flux:text>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <flux:button variant="subtle" type="submit" size="sm">{{ __('Log out') }}</flux:button>
        </form>
    </div>
</x-layouts::auth>
