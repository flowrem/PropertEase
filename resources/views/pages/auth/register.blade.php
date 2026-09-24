<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="$teamInvitation ? __('Create an account') : __('Create your landlord account')"
            :description="$teamInvitation ? __('Enter your details below to create your account') : __('Enter your details below to start listing units on '.config('app.name'))"
        />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        @if ($teamInvitation)
            <x-team-invitation-alert :invitation="$teamInvitation" :action="__('Register')" />
        @endif

        <form method="POST" action="{{ route('register.store') }}" enctype="multipart/form-data" class="flex flex-col gap-6">
            @csrf

            @if ($teamInvitation)
                <input type="hidden" name="invitation" value="{{ $teamInvitation['code'] }}">
            @endif

            <!-- Name -->
            <flux:input
                name="name"
                :label="__('Name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('Full name')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="$teamInvitation['email'] ?? old('email')"
                type="email"
                required
                :readonly="(bool) $teamInvitation"
                autocomplete="email"
                placeholder="email@example.com"
            />

            @unless ($teamInvitation)
                <!-- Business or property name -->
                <flux:input
                    name="business_name"
                    :label="__('Business or property name')"
                    :value="old('business_name')"
                    type="text"
                    required
                    autocomplete="organization"
                    :placeholder="__('e.g. Dela Cruz Apartments')"
                />

                <!-- Valid ID -->
                <div class="flex flex-col gap-2">
                    <flux:input
                        name="verification_id"
                        :label="__('Valid ID')"
                        type="file"
                        accept=".jpg,.jpeg,.png,.pdf"
                        required
                    />
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('A government-issued ID (JPG, PNG or PDF, up to 5 MB). Only the :app team can see it, and it is deleted 30 days after we review it.', ['app' => config('app.name')]) }}
                    </flux:text>
                </div>

                <flux:field variant="inline">
                    <flux:checkbox name="consent" value="1" :checked="old('consent')" />
                    <flux:label>{{ __('I agree to share my ID with :app so it can confirm I am a real landlord.', ['app' => config('app.name')]) }}</flux:label>
                    <flux:error name="consent" />
                </flux:field>
            @endunless

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link
                :href="$teamInvitation ? route('login', ['invitation' => $teamInvitation['code']]) : route('login')"
                data-test="team-invitation-login-link"
                wire:navigate
            >
                {{ __('Log in') }}
            </flux:link>
        </div>
    </div>
</x-layouts::auth>
