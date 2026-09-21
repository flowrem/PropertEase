<?php

use App\Models\Announcement;
use App\Models\Property;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Announcements')] class extends Component
{
    public string $content = '';

    public string $propertyId = 'all';

    public bool $showDeleteModal = false;

    public ?int $deletingAnnouncementId = null;

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    #[Computed]
    public function isLandlord(): bool
    {
        return Auth::user()->isLandlordOn($this->team);
    }

    /**
     * @return Collection<int, Property>
     */
    #[Computed]
    public function properties(): Collection
    {
        return $this->team->properties()->get();
    }

    /**
     * Announcements visible to the current user: every property for a landlord,
     * or just the tenant's own property.
     *
     * @return Collection<int, Announcement>
     */
    #[Computed]
    public function announcements(): Collection
    {
        $query = Announcement::whereHas('property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['property', 'author'])
            ->latest();

        if ($this->isLandlord) {
            return $query->get();
        }

        $lease = Auth::user()->leases()
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->latest()
            ->first();

        if (! $lease) {
            return collect();
        }

        return $query->where('property_id', $lease->unit->property_id)->get();
    }

    public function post(): void
    {
        abort_unless($this->isLandlord, 403);

        $validated = $this->validate([
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $properties = $this->propertyId === 'all'
            ? $this->properties
            : $this->properties->where('id', (int) $this->propertyId)->values();

        abort_if($properties->isEmpty(), 404);

        foreach ($properties as $property) {
            $property->announcements()->create([
                'author_id' => Auth::id(),
                'content' => $validated['content'],
            ]);
        }

        $this->reset('content', 'propertyId');
        unset($this->announcements);

        Flux::toast(variant: 'success', text: __('Announcement posted.'));
    }

    public function confirmDelete(int $announcementId): void
    {
        abort_unless($this->isLandlord, 403);

        $this->deletingAnnouncementId = $this->teamAnnouncement($announcementId)->id;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        abort_unless($this->isLandlord, 403);

        if (! $this->deletingAnnouncementId) {
            return;
        }

        $this->teamAnnouncement($this->deletingAnnouncementId)->delete();

        $this->closeDeleteModal();
        unset($this->announcements);

        Flux::toast(variant: 'success', text: __('Announcement deleted.'));
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingAnnouncementId = null;
    }

    /**
     * Find an announcement belonging to the current team, or fail with a 404.
     */
    private function teamAnnouncement(int $announcementId): Announcement
    {
        return Announcement::whereHas('property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->findOrFail($announcementId);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Announcements') }}</flux:heading>
        <flux:subheading>
            {{ $this->isLandlord ? __('Post updates for your tenants.') : __('Notices from your landlord.') }}
        </flux:subheading>
    </div>

    @if ($this->isLandlord)
        <form wire:submit="post" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:select wire:model="propertyId" :label="__('Post to')">
                <flux:select.option value="all">{{ __('All properties') }}</flux:select.option>
                @foreach ($this->properties as $property)
                    <flux:select.option value="{{ $property->id }}">{{ $property->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea
                wire:model="content"
                :label="__('Announcement')"
                :placeholder="__('Scheduled maintenance, utility interruptions, reminders...')"
                rows="3"
                required
            />

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="megaphone">
                    {{ __('Post announcement') }}
                </flux:button>
            </div>
        </form>
    @endif

    @forelse ($this->announcements as $announcement)
        <div wire:key="announcement-{{ $announcement->id }}" class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <flux:badge color="zinc" size="sm">{{ $announcement->property->name }}</flux:badge>
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ $announcement->author->name }}</flux:text>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                        {{ $announcement->created_at->diffForHumans() }}
                    </flux:text>

                    @if ($this->isLandlord)
                        <flux:button
                            variant="ghost"
                            size="sm"
                            icon="trash"
                            :aria-label="__('Delete announcement')"
                            wire:click="confirmDelete({{ $announcement->id }})"
                        />
                    @endif
                </div>
            </div>
            <flux:text class="mt-2 block whitespace-pre-line">{{ $announcement->content }}</flux:text>
        </div>
    @empty
        <div class="rounded-xl border border-zinc-200 p-10 text-center dark:border-zinc-700">
            <flux:heading>{{ __('Nothing here yet') }}</flux:heading>
            <flux:subheading>
                {{ $this->isLandlord
                    ? __('Announcements you post will show up here.')
                    : __('Notices from your landlord will show up here.') }}
            </flux:subheading>
        </div>
    @endforelse

    @if ($this->isLandlord)
        <flux:modal name="delete-announcement-modal" class="max-w-md md:min-w-md" @close="closeDeleteModal" wire:model="showDeleteModal">
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Delete announcement') }}</flux:heading>
                    <flux:text>{{ __('Are you sure you want to delete this announcement? Tenants will no longer be able to see it.') }}</flux:text>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button variant="outline" wire:click="closeDeleteModal">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="danger" wire:click="delete">{{ __('Delete announcement') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
