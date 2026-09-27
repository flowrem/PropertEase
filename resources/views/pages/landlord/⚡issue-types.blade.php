<?php

use App\Models\IssueType;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Issue types')] class extends Component
{
    public string $name = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->canManageListingsOn($this->team);
    }

    /**
     * @return EloquentCollection<int, IssueType>
     */
    #[Computed]
    public function ownIssueTypes(): EloquentCollection
    {
        return $this->team->issueTypes()->orderByDesc('is_active')->orderBy('name')->get();
    }

    /**
     * @return EloquentCollection<int, IssueType>
     */
    #[Computed]
    public function platformDefaults(): EloquentCollection
    {
        return IssueType::query()->whereNull('team_id')->active()->orderBy('name')->get();
    }

    public function addIssueType(): void
    {
        Gate::authorize('create', [IssueType::class, $this->team]);

        $validated = $this->validate([
            'name' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $taken = IssueType::availableTo($this->team)
                        ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
                        ->exists();

                    if ($taken) {
                        $fail(__('An issue type with this name is already on your list.'));
                    }
                },
            ],
        ]);

        $this->team->issueTypes()->create(['name' => trim($validated['name'])]);

        $this->reset('name');
        unset($this->ownIssueTypes);

        Flux::toast(variant: 'success', text: __('Issue type added.'));
    }

    public function toggleActive(int $issueTypeId): void
    {
        $issueType = $this->team->issueTypes()->findOrFail($issueTypeId);

        Gate::authorize('update', $issueType);

        $issueType->update(['is_active' => ! $issueType->is_active]);

        unset($this->ownIssueTypes);
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:link :href="route('landlord.maintenance')" wire:navigate class="text-sm">&larr; {{ __('Maintenance') }}</flux:link>
        <flux:heading size="xl" level="1" class="mt-2">{{ __('Issue types') }}</flux:heading>
        <flux:subheading>{{ __('What your tenants can pick when they report a problem.') }}</flux:subheading>
    </div>

    <div class="space-y-4">
        <div>
            <flux:heading size="lg" level="2">{{ __('Your own issue types') }}</flux:heading>
            <flux:text>{{ __('Add anything the list below is missing. A deactivated one stays on the reports that used it but cannot be picked for new ones.') }}</flux:text>
        </div>

        @if ($this->canManage)
            <form wire:submit="addIssueType" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <flux:input wire:model="name" :label="__('Name')" :placeholder="__('Gate remote not working')" maxlength="60" required />
                </div>
                <flux:button type="submit" variant="primary" icon="plus">{{ __('Add') }}</flux:button>
            </form>
        @else
            <flux:text class="text-zinc-500">{{ __('You can view this list. Ask your landlord or a manager to change it.') }}</flux:text>
        @endif

        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white">
            @forelse ($this->ownIssueTypes as $issueType)
                <div wire:key="issue-type-{{ $issueType->id }}" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="flex items-center gap-2">
                        <flux:text class="font-medium text-zinc-800">{{ $issueType->name }}</flux:text>
                        @unless ($issueType->is_active)
                            <flux:badge size="sm" color="amber">{{ __('Inactive') }}</flux:badge>
                        @endunless
                    </div>

                    @if ($this->canManage)
                        <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $issueType->id }})">
                            {{ $issueType->is_active ? __('Deactivate') : __('Activate') }}
                        </flux:button>
                    @endif
                </div>
            @empty
                <flux:text class="px-4 py-3 text-zinc-500">{{ __('You have not added any of your own yet.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Included for every landlord') }}</flux:heading>

        <div class="flex flex-wrap gap-2">
            @foreach ($this->platformDefaults as $issueType)
                <flux:badge color="zinc">{{ $issueType->name }}</flux:badge>
            @endforeach
        </div>
    </div>
</section>
