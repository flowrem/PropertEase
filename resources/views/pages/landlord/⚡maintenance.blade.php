<?php

use App\Enums\ConcernCategory;
use App\Enums\ConcernStatus;
use App\Enums\ItemServiceAction;
use App\Models\Concern;
use App\Models\Team;
use App\Notifications\ReportUpdated;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Maintenance')] class extends Component
{
    /**
     * The report open in the panel, kept in the address so a notification
     * can link straight to it.
     */
    #[Url(as: 'report')]
    public ?int $openReportId = null;

    public bool $showReportPanel = false;

    public string $message = '';

    public string $newStatus = '';

    /**
     * What was done to the reported item when resolving: repaired,
     * replaced, or 'none' when no work on it was needed.
     */
    public string $outcome = '';

    public string $cost = '';

    public string $performedAt = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * @return Builder<Concern>
     */
    protected function teamReports(): Builder
    {
        return Concern::query()
            ->where('category', ConcernCategory::Maintenance->value)
            ->whereHas('lease.unit.property', fn ($query) => $query->where('team_id', $this->team->id));
    }

    /**
     * This team's maintenance requests: unresolved first, most important
     * priority first, and oldest first within a priority.
     *
     * @return Collection<int, Concern>
     */
    #[Computed]
    public function concerns(): Collection
    {
        return $this->teamReports()
            ->with(['lease.tenant', 'lease.unit'])
            ->get()
            ->sort(fn (Concern $a, Concern $b) => [$a->status === ConcernStatus::Resolved, $b->priority->rank(), $a->created_at]
                <=> [$b->status === ConcernStatus::Resolved, $a->priority->rank(), $b->created_at])
            ->values();
    }

    #[Computed]
    public function openReport(): ?Concern
    {
        if (! $this->openReportId) {
            return null;
        }

        return $this->teamReports()
            ->with(['lease.tenant', 'lease.unit', 'issueType', 'unitItem', 'itemService', 'updates' => fn ($updates) => $updates->with('author')->latest()])
            ->find($this->openReportId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->canManageListingsOn($this->team);
    }

    /**
     * Open the report named in the address, if it is one of this team's.
     */
    public function mount(): void
    {
        $this->performedAt = today()->toDateString();

        if ($this->openReport) {
            $this->showReportPanel = true;
        } else {
            $this->openReportId = null;
        }
    }

    public function openReportPanel(int $concernId): void
    {
        $this->openReportId = $this->teamReports()->findOrFail($concernId)->id;
        $this->showReportPanel = true;
        $this->resetForm();
    }

    public function closeReportPanel(): void
    {
        $this->openReportId = null;
        $this->showReportPanel = false;
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('message', 'newStatus', 'outcome', 'cost');
        $this->performedAt = today()->toDateString();
        $this->resetValidation();
        unset($this->openReport);
    }

    /**
     * Post a reply and, optionally, move the report to another status. A
     * report about an inventory item asks what was done to it when resolved,
     * and a repair or replacement goes into that item's history.
     */
    public function saveUpdate(): void
    {
        $concern = $this->openReport;

        abort_unless($concern, 404);

        Gate::authorize('update', $concern);

        $statusChanges = $this->newStatus !== '' && $this->newStatus !== $concern->status->value;
        $resolving = $statusChanges && $this->newStatus === ConcernStatus::Resolved->value;
        $asksOutcome = $resolving && $concern->unitItem !== null && $concern->itemService === null;

        $validated = $this->validate([
            'message' => [$statusChanges ? 'nullable' : 'required', 'string', 'max:2000'],
            'newStatus' => ['nullable', Rule::enum(ConcernStatus::class)],
            'outcome' => [$asksOutcome ? 'required' : 'nullable', Rule::in(['repaired', 'replaced', 'none'])],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'performedAt' => [$asksOutcome ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
        ], [
            'message.required' => __('Write a reply, or pick a new status.'),
        ], [
            'newStatus' => __('status'),
            'performedAt' => __('date of the work'),
        ]);

        $status = $statusChanges ? ConcernStatus::from($validated['newStatus']) : null;
        $message = trim((string) $validated['message']) ?: __('Marked :status.', ['status' => $status?->label()]);

        $update = DB::transaction(function () use ($concern, $status, $message, $asksOutcome, $validated) {
            $update = $concern->updates()->create([
                'author_id' => Auth::id(),
                'message' => $message,
                'new_status' => $status,
            ]);

            if ($status) {
                $concern->update([
                    'status' => $status,
                    'resolved_at' => $status === ConcernStatus::Resolved ? now() : null,
                ]);
            }

            if ($asksOutcome && $validated['outcome'] !== 'none') {
                $concern->unitItem->services()->create([
                    'concern_id' => $concern->id,
                    'action' => ItemServiceAction::from($validated['outcome']),
                    'performed_at' => $validated['performedAt'],
                    'cost' => $validated['cost'] !== '' ? $validated['cost'] : null,
                    'notes' => trim((string) $validated['message']) ?: null,
                    'recorded_by' => Auth::id(),
                ]);
            }

            return $update;
        });

        $concern->lease->tenant->notify(new ReportUpdated($update));

        $this->resetForm();
        unset($this->concerns);

        Flux::toast(variant: 'success', text: $status === ConcernStatus::Resolved ? __('Report resolved.') : __('Update posted.'));
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Maintenance') }}</flux:heading>
        <flux:subheading>{{ __('Repair requests from every tenant under :team, most urgent first. Open one to reply or resolve it.', ['team' => $this->team->name]) }}</flux:subheading>
    </div>

    <x-concern-list
        :concerns="$this->concerns"
        show-priority
        open-action="openReportPanel"
        :empty-title="__('Nothing here yet')"
        :empty-text="__('Repair requests your tenants submit will show up here.')"
    />

    <flux:modal name="report-panel" class="w-full max-w-2xl" wire:model="showReportPanel" @close="closeReportPanel">
        @if ($report = $this->openReport)
            <div class="space-y-5">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$report->priority->color()">{{ $report->priority->label() }}</flux:badge>
                        <flux:badge size="sm" :color="$report->status->color()">{{ $report->status->label() }}</flux:badge>
                    </div>
                    <flux:heading size="lg">{{ $report->title }}</flux:heading>
                    <flux:text>
                        {{ $report->lease->tenant->name }} &middot; {{ __('Unit :number', ['number' => $report->lease->unit->unit_number]) }}
                        &middot; {{ $report->created_at->format('M j, Y g:i A') }}
                    </flux:text>
                </div>

                <flux:text class="whitespace-pre-line text-zinc-800">{{ $report->description }}</flux:text>

                <div class="flex flex-wrap gap-4 text-sm">
                    @if ($report->unitItem)
                        <flux:link :href="route('units.inventory', ['unit' => $report->lease->unit_id])" wire:navigate>
                            {{ __('Item: :item', ['item' => $report->unitItem->name]) }}
                        </flux:link>
                    @endif
                    @if ($report->photo_path)
                        <flux:link :href="route('concerns.photo', ['concern' => $report->id])" target="_blank">{{ __('View photo') }}</flux:link>
                    @endif
                </div>

                @if ($report->itemService)
                    <flux:callout icon="wrench" color="green">
                        <flux:callout.text>
                            {{ __(':action on :date', ['action' => $report->itemService->action->label(), 'date' => $report->itemService->performed_at->format('M j, Y')]) }}
                            @if ($report->itemService->cost !== null)
                                &middot; &#8369;{{ number_format((float) $report->itemService->cost, 2) }}
                            @endif
                        </flux:callout.text>
                    </flux:callout>
                @endif

                @if ($report->updates->isNotEmpty())
                    <ul class="space-y-2 border-t border-zinc-200 pt-3 text-sm">
                        @foreach ($report->updates as $update)
                            <li wire:key="update-{{ $update->id }}">
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

                @if ($this->canManage)
                    <form wire:submit="saveUpdate" class="space-y-4 border-t border-zinc-200 pt-4">
                        <flux:textarea wire:model="message" :label="__('Reply to the tenant')" rows="2" maxlength="2000" :placeholder="__('A plumber is coming tomorrow morning.')" />

                        <flux:radio.group wire:model.live="newStatus" :label="__('Status')" variant="segmented">
                            @foreach (ConcernStatus::cases() as $status)
                                <flux:radio value="{{ $status === $report->status ? '' : $status->value }}">{{ $status->label() }}</flux:radio>
                            @endforeach
                        </flux:radio.group>

                        @if ($newStatus === ConcernStatus::Resolved->value && $report->unitItem && ! $report->itemService)
                            <div class="space-y-3 rounded-lg border border-sand bg-brand-50 p-3">
                                <flux:radio.group wire:model="outcome" :label="__('What was done to the :item?', ['item' => $report->unitItem->name])">
                                    <flux:radio value="repaired" :label="__('Repaired')" />
                                    <flux:radio value="replaced" :label="__('Replaced')" />
                                    <flux:radio value="none" :label="__('No work on it was needed')" />
                                </flux:radio.group>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <flux:input wire:model="performedAt" type="date" max="{{ today()->toDateString() }}" :label="__('Done on')" />
                                    <flux:input wire:model="cost" type="number" step="0.01" min="0" :label="__('Cost (₱, optional)')" />
                                </div>
                            </div>
                        @endif

                        <div class="flex justify-end gap-2">
                            <flux:button variant="filled" wire:click="closeReportPanel">{{ __('Close') }}</flux:button>
                            <flux:button type="submit" variant="primary">{{ __('Post update') }}</flux:button>
                        </div>
                    </form>
                @else
                    <flux:text>{{ __('Only the landlord or a manager can reply to or resolve reports.') }}</flux:text>
                @endif
            </div>
        @endif
    </flux:modal>
</section>
