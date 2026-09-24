<?php

use App\Enums\PaymentMethod;
use App\Models\PaymentChannel;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Payment settings')] class extends Component
{
    use WithFileUploads;

    public bool $showFormModal = false;

    public ?int $editingChannelId = null;

    public string $method = 'gcash';

    public string $account_name = '';

    public string $account_number = '';

    public string $bank_name = '';

    public $qr = null;

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
     * @return Collection<int, PaymentChannel>
     */
    #[Computed]
    public function channels(): Collection
    {
        return $this->team->paymentChannels()->orderByDesc('is_active')->orderBy('id')->get();
    }

    /**
     * @return array<int, PaymentMethod>
     */
    #[Computed]
    public function methods(): array
    {
        return PaymentChannel::onlineMethods();
    }

    #[Computed]
    public function editingChannel(): ?PaymentChannel
    {
        return $this->editingChannelId
            ? $this->team->paymentChannels()->findOrFail($this->editingChannelId)
            : null;
    }

    public function openCreate(): void
    {
        Gate::authorize('create', [PaymentChannel::class, $this->team]);

        $this->resetForm();
        $this->showFormModal = true;
    }

    public function openEdit(int $channelId): void
    {
        $channel = $this->team->paymentChannels()->findOrFail($channelId);

        Gate::authorize('update', $channel);

        $this->resetForm();
        $this->editingChannelId = $channel->id;
        $this->method = $channel->method->value;
        $this->account_name = $channel->account_name;
        $this->account_number = (string) $channel->account_number;
        $this->bank_name = (string) $channel->bank_name;
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetForm();
    }

    public function save(): void
    {
        $channel = $this->editingChannel;

        $channel
            ? Gate::authorize('update', $channel)
            : Gate::authorize('create', [PaymentChannel::class, $this->team]);

        $isBank = $this->method === PaymentMethod::BankTransfer->value;
        $needsQr = ! $isBank && ! $channel?->qr_path;

        $validated = $this->validate([
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $method) => $method->value, PaymentChannel::onlineMethods()))],
            'account_name' => ['required', 'string', 'max:100'],
            'account_number' => [$isBank ? 'required' : 'nullable', 'string', 'max:50'],
            'bank_name' => [$isBank ? 'required' : 'nullable', 'string', 'max:100'],
            'qr' => [$needsQr ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'qr.required' => __('A GCash channel needs its QR code so applicants can scan it.'),
        ]);

        $attributes = [
            'method' => $validated['method'],
            'account_name' => $validated['account_name'],
            'account_number' => $validated['account_number'] ?: null,
            'bank_name' => $isBank ? $validated['bank_name'] : null,
        ];

        if ($this->qr) {
            $mediaDisk = config('filesystems.media_disk');

            if ($channel?->qr_path) {
                Storage::disk($mediaDisk)->delete($channel->qr_path);
            }

            $attributes['qr_path'] = $this->qr->store('qr', $mediaDisk);
        }

        $channel
            ? $channel->update($attributes)
            : $this->team->paymentChannels()->create($attributes);

        unset($this->channels);
        $this->closeFormModal();

        Flux::toast(variant: 'success', text: __('Payment channel saved.'));
    }

    public function toggleActive(int $channelId): void
    {
        $channel = $this->team->paymentChannels()->findOrFail($channelId);

        Gate::authorize('update', $channel);

        $channel->update(['is_active' => ! $channel->is_active]);

        unset($this->channels);
    }

    protected function resetForm(): void
    {
        $this->reset('editingChannelId', 'method', 'account_name', 'account_number', 'bank_name', 'qr');
        $this->resetValidation();
        unset($this->editingChannel);
    }
}; ?>

<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Payment settings') }}</flux:heading>
            <flux:subheading>{{ __('Where applicants send the reservation downpayment. Shown on every one of your listings.') }}</flux:subheading>
        </div>

        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreate">{{ __('Add channel') }}</flux:button>
        @endif
    </div>

    @if ($this->channels->where('is_active', true)->isEmpty())
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>
                {{ __('You have no active payment channel. Listings cannot be submitted for review until you add one, and applicants cannot reserve until then.') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    @if (! $this->canManage)
        <flux:text class="text-zinc-400">{{ __('You can view these settings. Ask your landlord or a manager to change them.') }}</flux:text>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($this->channels as $channel)
            <div wire:key="channel-{{ $channel->id }}" class="flex gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                @if ($channel->qrUrl())
                    <img
                        src="{{ $channel->qrUrl() }}"
                        alt="{{ __(':method QR code for :name', ['method' => $channel->method->label(), 'name' => $channel->account_name]) }}"
                        loading="lazy"
                        width="96"
                        height="96"
                        class="size-24 shrink-0 rounded bg-white object-contain"
                    >
                @endif

                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="sm">{{ $channel->method->label() }}</flux:heading>
                        <flux:badge size="sm" :color="$channel->is_active ? 'lime' : 'zinc'">
                            {{ $channel->is_active ? __('Active') : __('Inactive') }}
                        </flux:badge>
                    </div>
                    <flux:text class="truncate">{{ $channel->account_name }}</flux:text>
                    @if ($channel->bank_name)
                        <flux:text class="text-zinc-400">{{ $channel->bank_name }}</flux:text>
                    @endif
                    @if ($channel->account_number)
                        <flux:text class="text-zinc-400">{{ $channel->account_number }}</flux:text>
                    @endif

                    @if ($this->canManage)
                        <div class="mt-2 flex gap-2">
                            <flux:button size="sm" wire:click="openEdit({{ $channel->id }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $channel->id }})">
                                {{ $channel->is_active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                        </div>
                    @endif
                </div>
            </div>
        @empty
            <flux:text class="text-zinc-400">{{ __('No payment channels yet.') }}</flux:text>
        @endforelse
    </div>

    <flux:modal name="payment-channel-modal" class="max-w-lg md:min-w-lg" @close="closeFormModal" wire:model="showFormModal">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingChannelId ? __('Edit payment channel') : __('Add payment channel') }}</flux:heading>

            <flux:select wire:model.live="method" :label="__('Method')">
                @foreach ($this->methods as $method)
                    <flux:select.option value="{{ $method->value }}">{{ $method->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="account_name" :label="__('Account name')" required />

            @if ($method === 'bank_transfer')
                <flux:input wire:model="bank_name" :label="__('Bank name')" required />
                <flux:input wire:model="account_number" :label="__('Account number')" required />
            @else
                <flux:input wire:model="account_number" :label="__('Mobile number (optional)')" />
            @endif

            <div class="space-y-2">
                <flux:input
                    wire:model="qr"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    :label="$method === 'bank_transfer' ? __('QR code (optional)') : __('GCash QR code')"
                />
                <flux:text class="text-zinc-400">{{ __('JPG, PNG or WebP, up to 2 MB. Use the "Receive money" QR from your GCash app.') }}</flux:text>
                @if ($this->editingChannel?->qrUrl())
                    <flux:text class="text-zinc-400">{{ __('Leave empty to keep the current QR code.') }}</flux:text>
                @endif
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="filled" wire:click="closeFormModal">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
