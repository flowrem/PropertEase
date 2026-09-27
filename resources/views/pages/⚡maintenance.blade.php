<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use App\Enums\LeaseStatus;
use App\Enums\TeamRole;
use App\Models\Concern;
use App\Models\IssueType;
use App\Models\Lease;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TenantReportSubmitted;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Maintenance')] class extends Component
{
    use WithFileUploads;

    public const MAX_REPORTS_PER_HOUR = 10;

    public string $issue_type_id = '';

    public string $unit_item_id = '';

    public string $priority = 'medium';

    public string $description = '';

    public $photo = null;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The tenant's active lease on this team, with their unit's items.
     */
    #[Computed]
    public function lease(): ?Lease
    {
        return Auth::user()->leases()
            ->where('status', LeaseStatus::Active->value)
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['unit.property', 'unit.items' => fn ($items) => $items->active()->orderBy('name')])
            ->latest()
            ->first();
    }

    /**
     * @return EloquentCollection<int, IssueType>
     */
    #[Computed]
    public function issueTypes(): EloquentCollection
    {
        return IssueType::availableTo($this->team)->active()
            ->orderByRaw("case when name = 'Other' then 1 else 0 end")
            ->orderBy('name')
            ->get();
    }

    /**
     * Every maintenance report this tenant sent on this team, across all of
     * their leases here, newest first.
     *
     * @return EloquentCollection<int, Concern>
     */
    #[Computed]
    public function reports(): EloquentCollection
    {
        return Concern::query()
            ->where('category', ConcernCategory::Maintenance->value)
            ->whereHas('lease', fn ($leases) => $leases
                ->where('tenant_id', Auth::id())
                ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id)))
            ->with(['lease.unit', 'unitItem', 'updates' => fn ($updates) => $updates->with('author')->latest()])
            ->latest()
            ->get();
    }

    public function submit(): void
    {
        $lease = $this->lease;

        abort_unless($lease, 403);

        $validated = $this->validate([
            'issue_type_id' => ['required', Rule::in($this->issueTypes->modelKeys())],
            'unit_item_id' => ['nullable', Rule::in($lease->unit->items->modelKeys())],
            'priority' => ['required', Rule::enum(ConcernPriority::class)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], attributes: [
            'issue_type_id' => __('issue'),
            'unit_item_id' => __('affected item'),
        ]);

        $limiterKey = 'tenant-report:'.Auth::id();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_REPORTS_PER_HOUR)) {
            $this->addError('description', __('You have sent a lot of reports in the last hour. Please try again later.'));

            return;
        }

        RateLimiter::hit($limiterKey, 3600);

        $issueType = $this->issueTypes->find($validated['issue_type_id']);
        $item = $validated['unit_item_id'] ? $lease->unit->items->find($validated['unit_item_id']) : null;

        $concern = $lease->concerns()->create([
            'category' => ConcernCategory::Maintenance,
            'issue_type_id' => $issueType->id,
            'unit_item_id' => $item?->id,
            'title' => $item ? "{$issueType->name}: {$item->name}" : $issueType->name,
            'description' => trim($validated['description']),
            'priority' => $validated['priority'],
            'status' => ConcernStatus::Pending,
            'photo_path' => $this->photo?->store("concerns/{$lease->id}", config('filesystems.sensitive_disk')),
        ]);

        $this->team->members()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value, TeamRole::Member->value])
            ->get()
            ->each(fn (User $member) => $member->notify(new TenantReportSubmitted($concern)));

        $this->reset('issue_type_id', 'unit_item_id', 'priority', 'description', 'photo');
        unset($this->reports);

        Flux::toast(variant: 'success', text: __('Report sent to your landlord.'));
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Maintenance') }}</flux:heading>
        <flux:subheading>{{ __('Report something broken in your unit and follow it until it is fixed.') }}</flux:subheading>
    </div>

    @if ($this->lease)
        <form wire:submit="submit" class="space-y-4 rounded-lg border border-zinc-200 p-4">
            <flux:heading size="lg" level="2">{{ __('Report an issue') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="issue_type_id" :label="__('What is wrong?')" :placeholder="__('Choose an issue')" required>
                    @foreach ($this->issueTypes as $issueType)
                        <flux:select.option value="{{ $issueType->id }}">{{ $issueType->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="unit_item_id" :label="__('Which item? (optional)')" :placeholder="$this->lease->unit->items->isEmpty() ? __('No items listed for your unit') : __('Not sure, or not listed')">
                    <flux:select.option value="">{{ __('Not sure, or not listed') }}</flux:select.option>
                    @foreach ($this->lease->unit->items as $item)
                        <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:radio.group wire:model="priority" :label="__('How urgent is it?')" variant="segmented">
                @foreach (ConcernPriority::cases() as $option)
                    <flux:radio value="{{ $option->value }}">{{ $option->label() }}</flux:radio>
                @endforeach
            </flux:radio.group>

            <flux:textarea wire:model="description" :label="__('Describe the problem')" :placeholder="__('Since this morning the kitchen faucet keeps dripping, even when fully closed.')" rows="3" maxlength="2000" required />

            <flux:input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" :label="__('Photo (optional)')" :description="__('JPG, PNG or WebP, up to 4 MB. Only you and your landlord can see it.')" />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send report') }}</flux:button>
            </div>
        </form>
    @else
        <flux:text>{{ __('You can report issues once you are renting a unit here.') }}</flux:text>
    @endif

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Your reports') }}</flux:heading>

        @forelse ($this->reports as $report)
            <div wire:key="report-{{ $report->id }}" class="space-y-2 rounded-lg border border-zinc-200 p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$report->status->color()">{{ $report->status->label() }}</flux:badge>
                        <flux:heading size="sm">{{ $report->title }}</flux:heading>
                    </div>
                    <flux:text class="text-xs">{{ $report->created_at->format('M j, Y g:i A') }}</flux:text>
                </div>

                <flux:text>{{ $report->description }}</flux:text>

                @if ($report->photo_path)
                    <flux:link :href="route('concerns.photo', ['concern' => $report->id])" target="_blank" class="text-sm">{{ __('View photo') }}</flux:link>
                @endif

                @if ($report->updates->isNotEmpty())
                    <ul class="space-y-1 border-t border-zinc-200 pt-2 text-sm">
                        @foreach ($report->updates as $update)
                            <li>
                                <span class="font-medium">{{ $update->author->name }}</span>
                                <span class="text-zinc-500">&middot; {{ $update->created_at->format('M j, g:i A') }}</span>
                                @if ($update->new_status)
                                    <flux:badge size="sm" :color="$update->new_status->color()">{{ $update->new_status->label() }}</flux:badge>
                                @endif
                                <div>{{ $update->message }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <flux:text>{{ __('You have not reported anything yet.') }}</flux:text>
        @endforelse
    </div>
</section>
