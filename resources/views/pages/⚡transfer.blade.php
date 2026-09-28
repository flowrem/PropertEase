<?php

use App\Actions\Transfers\RequestTransfer;
use App\Enums\LeaseStatus;
use App\Enums\TransferStatus;
use App\Models\Lease;
use App\Models\Team;
use App\Models\TransferRequest;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Transfer')] class extends Component
{
    public const MAX_DAYS_AHEAD = 90;

    public ?int $to_unit_id = null;

    public string $preferred_date = '';

    public string $reason = '';

    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * The tenant's active lease on this team.
     */
    #[Computed]
    public function lease(): ?Lease
    {
        return Auth::user()->leases()
            ->where('status', LeaseStatus::Active->value)
            ->whereHas('unit.property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->with(['unit.property', 'currentRent'])
            ->latest()
            ->first();
    }

    /**
     * The tenant's transfer requests on this team, newest first.
     *
     * @return EloquentCollection<int, TransferRequest>
     */
    #[Computed]
    public function requests(): EloquentCollection
    {
        return TransferRequest::query()
            ->where('team_id', $this->team->id)
            ->where('tenant_id', Auth::id())
            ->with(['fromUnit.property', 'toUnit.property'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function openRequest(): ?TransferRequest
    {
        return $this->requests->first(fn (TransferRequest $transfer): bool => $transfer->status->isOpen());
    }

    /**
     * The landlord's other units with room right now.
     *
     * @return EloquentCollection<int, Unit>
     */
    #[Computed]
    public function availableUnits(): EloquentCollection
    {
        if (! $this->lease) {
            return new EloquentCollection;
        }

        return Unit::query()
            ->whereHas('property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->whereKeyNot($this->lease->unit_id)
            ->hasRoom()
            ->withCount(['activeLeases', 'heldReservations', 'incomingTransfers'])
            ->with('property')
            ->orderBy('property_id')
            ->orderBy('unit_number')
            ->get();
    }

    /**
     * What the tenant pays now, and would pay in the unit they picked.
     *
     * @return array{current: float, new: float}|null
     */
    #[Computed]
    public function rentChange(): ?array
    {
        $unit = $this->availableUnits->firstWhere('id', $this->to_unit_id);

        if (! $unit || ! $this->lease) {
            return null;
        }

        return [
            'current' => $this->lease->currentRent ? (float) $this->lease->currentRent->amount : $this->lease->unit->rentSharePerTenant(),
            'new' => $unit->rentShareForNewcomer(),
        ];
    }

    public function submit(RequestTransfer $requestTransfer): void
    {
        abort_unless($this->lease, 404);

        $validated = $this->validate([
            'to_unit_id' => ['required', 'integer'],
            'preferred_date' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.today()->addDays(self::MAX_DAYS_AHEAD)->toDateString()],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'to_unit_id.required' => __('Choose the unit you want to move to.'),
        ]);

        $toUnit = Unit::query()
            ->whereHas('property', fn ($properties) => $properties->where('team_id', $this->team->id))
            ->find($validated['to_unit_id']);

        if (! $toUnit) {
            $this->addError('to_unit_id', __('Choose one of your landlord\'s units.'));

            return;
        }

        $requestTransfer->handle($this->lease, $toUnit, $validated['reason'], CarbonImmutable::parse($validated['preferred_date']));

        $this->reset('to_unit_id', 'preferred_date', 'reason');
        unset($this->requests, $this->openRequest, $this->availableUnits);

        Flux::toast(variant: 'success', text: __('Request sent. Your landlord will reply here.'));
    }

    public function withdraw(): void
    {
        $transfer = $this->openRequest;

        abort_unless($transfer, 404);

        Gate::authorize('withdraw', $transfer);

        $transfer->forceFill(['status' => TransferStatus::Cancelled])->save();

        unset($this->requests, $this->openRequest);

        Flux::toast(variant: 'success', text: __('Request withdrawn.'));
    }
}; ?>

<section class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Transfer') }}</flux:heading>
        <flux:subheading>{{ __('Ask to move to another unit of the same landlord.') }}</flux:subheading>
    </div>

    @if (! $this->lease)
        <flux:callout icon="information-circle">
            <flux:callout.text>{{ __('You don\'t have a unit with this landlord yet.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:text class="text-zinc-500">{{ __('You live in') }}</flux:text>
            <flux:heading size="sm">
                {{ $this->lease->unit->property->name }} &middot; {{ __('Unit :number', ['number' => $this->lease->unit->unit_number]) }}
            </flux:heading>
        </div>

        @if ($transfer = $this->openRequest)
            <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading size="sm">{{ __('Move to Unit :number', ['number' => $transfer->toUnit->unit_number]) }}</flux:heading>
                    <flux:badge size="sm" :color="$transfer->status->color()">{{ $transfer->status->label() }}</flux:badge>
                </div>

                @if ($transfer->status === TransferStatus::Pending)
                    <flux:text>{{ __('Waiting for your landlord. You asked to move on :date.', ['date' => $transfer->preferred_date->format('M j, Y')]) }}</flux:text>
                    <div class="flex justify-end">
                        <flux:button size="sm" variant="ghost" wire:click="withdraw">{{ __('Withdraw request') }}</flux:button>
                    </div>
                @else
                    <flux:text>{{ __('Approved. Your landlord will move you on :date, after checking both units with you.', ['date' => $transfer->move_date?->format('M j, Y')]) }}</flux:text>
                @endif
            </div>
        @elseif ($this->availableUnits->isEmpty())
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ __('None of your landlord\'s other units has room right now. Check again later.') }}</flux:callout.text>
            </flux:callout>
        @else
            <form wire:submit="submit" class="flex flex-col gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:select wire:model.live="to_unit_id" :label="__('Move to')">
                    <flux:select.option value="">{{ __('Choose a unit') }}</flux:select.option>
                    @foreach ($this->availableUnits as $unit)
                        <flux:select.option value="{{ $unit->id }}">
                            {{ $unit->property->name }} &middot; {{ __('Unit :number', ['number' => $unit->unit_number]) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                @if ($change = $this->rentChange)
                    <p class="rounded-lg bg-sand/40 p-3 text-sm text-zinc-800">
                        {{ __('Your rent would change from :current to :new per month.', [
                            'current' => '₱'.number_format($change['current'], 2),
                            'new' => '₱'.number_format($change['new'], 2),
                        ]) }}
                    </p>
                @endif

                <flux:input wire:model="preferred_date" type="date" :min="today()->toDateString()" :max="today()->addDays(90)->toDateString()" :label="__('When would you like to move?')" />
                <flux:textarea wire:model="reason" rows="3" maxlength="500" :label="__('Why do you want to move?')" :placeholder="__('I need a unit on the ground floor for my knee.')" />

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">{{ __('Send request') }}</flux:button>
                </div>
            </form>
        @endif
    @endif

    @if ($this->requests->reject(fn ($transfer) => $transfer->status->isOpen())->isNotEmpty())
        <div class="space-y-2">
            <flux:heading size="sm">{{ __('Earlier requests') }}</flux:heading>
            @foreach ($this->requests->reject(fn ($transfer) => $transfer->status->isOpen()) as $transfer)
                <div wire:key="transfer-{{ $transfer->id }}" class="rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                    <div class="flex items-center justify-between gap-2">
                        <span>{{ __('Unit :from to Unit :to', ['from' => $transfer->fromUnit->unit_number, 'to' => $transfer->toUnit->unit_number]) }} &middot; {{ $transfer->created_at?->format('M j, Y') }}</span>
                        <flux:badge size="sm" :color="$transfer->status->color()">{{ $transfer->status->label() }}</flux:badge>
                    </div>
                    @if ($transfer->decision_reason)
                        <flux:text class="mt-1 text-zinc-600">{{ __('Reason: :reason', ['reason' => $transfer->decision_reason]) }}</flux:text>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
