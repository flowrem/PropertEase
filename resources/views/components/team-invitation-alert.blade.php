@props([
    'invitation',
    'action',
])

<div data-test="team-invitation-alert">
    <div class="flex gap-3 rounded-lg border border-coastal-200 bg-coastal-50 px-4 py-3 text-sm text-coastal-900 dark:border-coastal-600 dark:bg-coastal-800 dark:text-coastal-100">
        <flux:icon name="envelope-open" class="mt-0.5 size-4 shrink-0 text-coastal-600 dark:text-coastal-300" />

        <div>
            {{ __(':action to join the ":team" team.', ['action' => $action, 'team' => $invitation['teamName']]) }}
        </div>
    </div>
</div>
