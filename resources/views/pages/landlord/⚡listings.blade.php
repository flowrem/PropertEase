<?php

use App\Enums\ListingStatus;
use App\Models\ListingPhoto;
use App\Models\Team;
use App\Models\Unit;
use App\Models\UnitListing;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Listings')] class extends Component
{
    use WithFileUploads;

    public const MAX_PHOTOS = 8;

    public bool $showFormModal = false;

    public ?int $editingListingId = null;

    public ?int $unit_id = null;

    public string $title = '';

    public string $description = '';

    public string $contact_name = '';

    public string $contact_phone = '';

    public string $contact_email = '';

    public string $downpayment_amount = '';

    /**
     * Ids of the listing's saved photos, in display order. Removing or moving
     * a photo only changes this list; it is applied when the form is saved.
     *
     * @var array<int, int>
     */
    public array $photoIds = [];

    /**
     * @var array<int, mixed>
     */
    public array $newPhotos = [];

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

    #[Computed]
    public function hasActiveChannel(): bool
    {
        return $this->team->paymentChannels()->active()->exists();
    }

    /**
     * @return Collection<int, UnitListing>
     */
    #[Computed]
    public function listings(): Collection
    {
        return UnitListing::forTeam($this->team)
            ->with(['unit.property', 'photos'])
            ->latest('updated_at')
            ->get();
    }

    /**
     * Units on this team that don't have a listing yet.
     *
     * @return Collection<int, Unit>
     */
    #[Computed]
    public function unitsWithoutListing(): Collection
    {
        return Unit::whereHas('property', fn ($query) => $query->where('team_id', $this->team->id))
            ->doesntHave('listing')
            ->with('property')
            ->get()
            ->sortBy(fn (Unit $unit) => $unit->property->name.' '.$unit->unit_number)
            ->values();
    }

    #[Computed]
    public function editingListing(): ?UnitListing
    {
        return $this->editingListingId
            ? UnitListing::forTeam($this->team)->with('unit.property')->findOrFail($this->editingListingId)
            : null;
    }

    /**
     * The saved photos currently shown in the form, in their staged order.
     *
     * @return Collection<int, ListingPhoto>
     */
    #[Computed]
    public function formPhotos(): Collection
    {
        $photos = ListingPhoto::whereIn('id', $this->photoIds)->get()->keyBy('id');

        return collect($this->photoIds)->map(fn (int $id) => $photos->get($id))->filter()->values();
    }

    public function openCreate(): void
    {
        abort_unless($this->canManage, 403);

        $this->resetForm();
        $this->contact_name = Auth::user()->name;
        $this->contact_email = Auth::user()->email;
        $this->showFormModal = true;
    }

    public function openEdit(int $listingId): void
    {
        $listing = UnitListing::forTeam($this->team)->findOrFail($listingId);

        Gate::authorize('update', $listing);

        $this->resetForm();
        $this->editingListingId = $listing->id;
        $this->unit_id = $listing->unit_id;
        $this->title = $listing->title;
        $this->description = $listing->description;
        $this->contact_name = $listing->contact_name;
        $this->contact_phone = $listing->contact_phone;
        $this->contact_email = $listing->contact_email;
        $this->downpayment_amount = $listing->downpayment_amount !== null ? (string) (float) $listing->downpayment_amount : '';
        $this->photoIds = $listing->photos()->pluck('id')->all();
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetForm();
    }

    public function removePhoto(int $photoId): void
    {
        $this->photoIds = array_values(array_diff($this->photoIds, [$photoId]));
    }

    public function movePhoto(int $photoId, int $direction): void
    {
        $index = array_search($photoId, $this->photoIds, true);
        $target = $index === false ? false : $index + $direction;

        if ($target === false || ! isset($this->photoIds[$target])) {
            return;
        }

        [$this->photoIds[$index], $this->photoIds[$target]] = [$this->photoIds[$target], $this->photoIds[$index]];
    }

    public function save(): void
    {
        $listing = $this->editingListing;

        if ($listing) {
            Gate::authorize('update', $listing);
        } else {
            $unit = $this->unitsWithoutListing->firstWhere('id', $this->unit_id);
            abort_if($unit === null, 404);
            Gate::authorize('create', [UnitListing::class, $unit]);
        }

        $validated = $this->validate([
            'unit_id' => [Rule::requiredIf(! $listing)],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'contact_name' => ['required', 'string', 'max:100'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'contact_email' => ['required', 'email', 'max:255'],
            'downpayment_amount' => ['nullable', 'numeric', 'min:1', 'max:99999999'],
            'newPhotos' => ['array'],
            'newPhotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        if (count($this->photoIds) + count($this->newPhotos) > self::MAX_PHOTOS) {
            $this->addError('newPhotos', __('A listing can have at most :max photos.', ['max' => self::MAX_PHOTOS]));

            return;
        }

        $mediaDisk = config('filesystems.media_disk');

        DB::transaction(function () use ($listing, $validated, $mediaDisk) {
            $attributes = [
                'title' => $validated['title'],
                'description' => $validated['description'],
                'contact_name' => $validated['contact_name'],
                'contact_phone' => $validated['contact_phone'],
                'contact_email' => $validated['contact_email'],
                'downpayment_amount' => $validated['downpayment_amount'] !== '' ? $validated['downpayment_amount'] : null,
            ];

            if ($listing) {
                $listing->fill($attributes);

                // Editing an approved listing hides it until it is approved again.
                if ($listing->status === ListingStatus::Approved) {
                    $listing->status = ListingStatus::PendingReview;
                    $listing->submitted_at = now();
                }

                $listing->save();
            } else {
                $listing = new UnitListing($attributes + ['unit_id' => $this->unit_id]);
                $listing->status = ListingStatus::Draft;
                $listing->save();
            }

            $removed = $listing->photos()->whereNotIn('id', $this->photoIds)->get();
            $removed->each(fn (ListingPhoto $photo) => Storage::disk($mediaDisk)->delete($photo->path));
            $listing->photos()->whereIn('id', $removed->modelKeys())->delete();

            foreach ($this->photoIds as $position => $photoId) {
                $listing->photos()->whereKey($photoId)->update(['sort_order' => $position]);
            }

            $position = count($this->photoIds);

            foreach ($this->newPhotos as $photo) {
                $listing->photos()->create([
                    'path' => $photo->store('listings', $mediaDisk),
                    'sort_order' => $position++,
                ]);
            }
        });

        unset($this->listings, $this->unitsWithoutListing);
        $this->closeFormModal();

        Flux::toast(variant: 'success', text: __('Listing saved.'));
    }

    public function submitForReview(int $listingId): void
    {
        $listing = UnitListing::forTeam($this->team)->withCount('photos')->findOrFail($listingId);

        Gate::authorize('update', $listing);

        if (! in_array($listing->status, [ListingStatus::Draft, ListingStatus::Rejected, ListingStatus::Unlisted], true)) {
            return;
        }

        if (! $this->hasActiveChannel) {
            Flux::toast(variant: 'danger', text: __('Add an active payment channel in Payment settings before submitting.'));

            return;
        }

        if ($listing->photos_count < 1) {
            Flux::toast(variant: 'danger', text: __('Add at least one photo before submitting.'));

            return;
        }

        $listing->status = ListingStatus::PendingReview;
        $listing->submitted_at = now();
        $listing->rejection_reason = null;
        $listing->save();

        unset($this->listings);

        Flux::toast(variant: 'success', text: __('Submitted for review.'));
    }

    public function unlist(int $listingId): void
    {
        $listing = UnitListing::forTeam($this->team)->findOrFail($listingId);

        Gate::authorize('update', $listing);

        if ($listing->status !== ListingStatus::Approved) {
            return;
        }

        $listing->status = ListingStatus::Unlisted;
        $listing->save();

        unset($this->listings);

        Flux::toast(variant: 'success', text: __('Listing taken down.'));
    }

    protected function resetForm(): void
    {
        $this->reset('editingListingId', 'unit_id', 'title', 'description', 'contact_name', 'contact_phone', 'contact_email', 'downpayment_amount', 'photoIds', 'newPhotos');
        $this->resetValidation();
        unset($this->editingListing, $this->formPhotos);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Listings') }}</flux:heading>
            <flux:subheading>{{ __('Advertise a unit publicly. Each listing is reviewed before it goes live.') }}</flux:subheading>
        </div>

        @if ($this->canManage && $this->unitsWithoutListing->isNotEmpty())
            <flux:button variant="primary" icon="plus" wire:click="openCreate">{{ __('New listing') }}</flux:button>
        @endif
    </div>

    @if ($this->canManage && ! $this->hasActiveChannel)
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>
                {{ __('You need an active payment channel before you can submit a listing for review.') }}
                <flux:link :href="route('payment-settings')" wire:navigate>{{ __('Go to Payment settings') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if (! $this->canManage)
        <flux:text class="text-zinc-400">{{ __('You can view listings. Ask your landlord or a manager to change them.') }}</flux:text>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        @forelse ($this->listings as $listing)
            <div wire:key="listing-{{ $listing->id }}" class="flex flex-col overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                @if ($listing->photos->first())
                    <img
                        src="{{ $listing->photos->first()->url() }}"
                        alt="{{ $listing->title }}"
                        loading="lazy"
                        width="640"
                        height="360"
                        class="aspect-video w-full object-cover"
                    >
                @endif

                <div class="flex flex-1 flex-col gap-2 p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="sm">{{ $listing->title }}</flux:heading>
                        <flux:badge size="sm" :color="$listing->status->color()">{{ $listing->status->label() }}</flux:badge>
                    </div>

                    <flux:text class="text-zinc-400">
                        {{ $listing->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $listing->unit->unit_number]) }}
                        &middot; {{ $listing->photos->count() }} {{ __('photo(s)') }}
                    </flux:text>

                    @if ($listing->status === ListingStatus::Rejected && $listing->rejection_reason)
                        <flux:callout variant="danger" icon="x-circle">
                            <flux:callout.heading>{{ __('Rejected') }}</flux:callout.heading>
                            <flux:callout.text>{{ $listing->rejection_reason }}</flux:callout.text>
                        </flux:callout>
                    @endif

                    @if ($this->canManage)
                        <div class="mt-auto flex flex-wrap gap-2 pt-2">
                            <flux:button size="sm" wire:click="openEdit({{ $listing->id }})">{{ __('Edit') }}</flux:button>

                            @if (in_array($listing->status, [ListingStatus::Draft, ListingStatus::Rejected, ListingStatus::Unlisted], true))
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    wire:click="submitForReview({{ $listing->id }})"
                                    :disabled="! $this->hasActiveChannel"
                                >
                                    {{ __('Submit for review') }}
                                </flux:button>
                            @endif

                            @if ($listing->status === ListingStatus::Approved)
                                <flux:button size="sm" variant="ghost" wire:click="unlist({{ $listing->id }})">{{ __('Unlist') }}</flux:button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <flux:text class="text-zinc-400">
                {{ __('No listings yet.') }}
                @if ($this->unitsWithoutListing->isEmpty())
                    {{ __('Add a unit under Properties first.') }}
                @endif
            </flux:text>
        @endforelse
    </div>

    @if ($this->canManage)
    <flux:modal name="listing-modal" class="max-w-2xl md:min-w-2xl" @close="closeFormModal" wire:model="showFormModal">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingListingId ? __('Edit listing') : __('New listing') }}</flux:heading>

            @if ($this->editingListing?->status === ListingStatus::Approved)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ __('This listing is live. Saving your changes takes it off the public pages until it is approved again.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($this->editingListing)
                <flux:text class="text-zinc-400">
                    {{ $this->editingListing->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $this->editingListing->unit->unit_number]) }}
                </flux:text>
            @else
                <flux:select wire:model="unit_id" :label="__('Unit')" required>
                    <flux:select.option value="">{{ __('Select a unit') }}</flux:select.option>
                    @foreach ($this->unitsWithoutListing as $unit)
                        <flux:select.option value="{{ $unit->id }}">
                            {{ $unit->property->name }} &mdash; {{ __('Unit :number', ['number' => $unit->unit_number]) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="title" :label="__('Title')" required />
            <flux:textarea wire:model="description" :label="__('Description')" rows="5" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="contact_name" :label="__('Contact name')" required />
                <flux:input wire:model="contact_phone" :label="__('Contact phone')" required />
                <flux:input wire:model="contact_email" type="email" :label="__('Contact email')" required />
                <flux:input wire:model="downpayment_amount" type="number" min="1" step="0.01" :label="__('Downpayment requested (optional)')" />
            </div>

            <div class="space-y-3">
                <flux:heading size="sm">{{ __('Photos') }}</flux:heading>

                @if ($this->formPhotos->isNotEmpty())
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ($this->formPhotos as $photo)
                            <div wire:key="photo-{{ $photo->id }}" class="flex flex-col gap-1">
                                <img src="{{ $photo->url() }}" alt="" loading="lazy" width="160" height="120" class="aspect-[4/3] w-full rounded object-cover">
                                <div class="flex gap-1">
                                    <flux:button size="xs" icon="arrow-left" :aria-label="__('Move earlier')" wire:click="movePhoto({{ $photo->id }}, -1)" />
                                    <flux:button size="xs" icon="arrow-right" :aria-label="__('Move later')" wire:click="movePhoto({{ $photo->id }}, 1)" />
                                    <flux:button size="xs" variant="danger" icon="trash" :aria-label="__('Remove photo')" wire:click="removePhoto({{ $photo->id }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <flux:input wire:model="newPhotos" type="file" multiple accept="image/jpeg,image/png,image/webp" :label="__('Add photos')" />
                <flux:text class="text-zinc-400">
                    {{ __('Up to :max photos, JPG, PNG or WebP, 4 MB each. The first photo is the cover.', ['max' => 8]) }}
                </flux:text>
                @error('newPhotos') <flux:text class="text-red-400">{{ $message }}</flux:text> @enderror
                @error('newPhotos.*') <flux:text class="text-red-400">{{ $message }}</flux:text> @enderror
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="closeFormModal">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
    @endif
</section>
