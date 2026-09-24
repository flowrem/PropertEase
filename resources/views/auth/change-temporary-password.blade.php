<x-layouts::auth :title="__('Choose a new password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Choose a new password')"
            :description="__('Enter the temporary password from your email, then pick a password only you know.')"
        />

        <form method="POST" action="{{ route('password.change.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="current_password"
                :label="__('Temporary password')"
                type="password"
                required
                autofocus
                autocomplete="current-password"
                viewable
            />

            <flux:input
                name="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm new password')"
                type="password"
                required
                autocomplete="new-password"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="change-password-button">
                {{ __('Update password') }}
            </flux:button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="text-center">
            @csrf
            <flux:button variant="subtle" type="submit" size="sm">{{ __('Log out') }}</flux:button>
        </form>
    </div>
</x-layouts::auth>
