<?php

namespace App\Models;

use App\Enums\BillingTiming;
use App\Enums\LeaseStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LeaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $unit_id
 * @property int $tenant_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int $due_day
 * @property BillingTiming $billing_timing
 * @property LeaseStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 * @property-read User $tenant
 * @property-read Collection<int, LeaseRent> $rents
 * @property-read LeaseRent|null $currentRent
 * @property-read Collection<int, ServiceSubscription> $serviceSubscriptions
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Invoice|null $currentInvoice
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, Concern> $concerns
 */
#[Fillable(['unit_id', 'tenant_id', 'start_date', 'end_date', 'due_day', 'billing_timing', 'status'])]
class Lease extends Model
{
    /** @use HasFactory<LeaseFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * @return HasMany<LeaseRent, $this>
     */
    public function rents(): HasMany
    {
        return $this->hasMany(LeaseRent::class);
    }

    /**
     * Get the lease's currently effective rent amount: the latest rent whose
     * effective date has actually arrived, so a change scheduled for a future
     * billing cycle doesn't apply early.
     *
     * @return HasOne<LeaseRent, $this>
     */
    public function currentRent(): HasOne
    {
        return $this->hasOne(LeaseRent::class)->ofMany([
            'effective_date' => 'max',
            'id' => 'max',
        ], fn (Builder $query) => $query->where('effective_date', '<=', today()->endOfDay()));
    }

    /**
     * The next occurrence of this lease's due day strictly after the given
     * date, rolling into the following month if that date has already
     * reached (or passed) this month's due day.
     */
    public function nextDueDateAfter(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $dueDate = $date->day($this->due_day);

        return $dueDate->greaterThan($date) ? $dueDate : $dueDate->addMonthNoOverflow();
    }

    /**
     * The next date a rent change should take effect for this lease: the
     * next occurrence of its due day strictly after today, so a billing
     * cycle already in progress is never changed retroactively.
     */
    public function nextRentEffectiveDate(): CarbonImmutable
    {
        return $this->nextDueDateAfter(today());
    }

    /**
     * Get whichever rent was effective on the given date: the latest rent
     * record whose effective date falls on or before it.
     */
    public function rentEffectiveOn(CarbonInterface $date): ?LeaseRent
    {
        return $this->rents()
            ->where('effective_date', '<=', CarbonImmutable::parse($date)->endOfDay())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * End this lease with the given terminal status, then let the unit
     * re-split rent among any remaining tenants or fall back to vacant.
     */
    public function end(LeaseStatus $status): void
    {
        $this->update([
            'status' => $status,
            'end_date' => now(),
        ]);

        $this->unit->resyncAfterLeaseEnded();
    }

    /**
     * @return HasMany<ServiceSubscription, $this>
     */
    public function serviceSubscriptions(): HasMany
    {
        return $this->hasMany(ServiceSubscription::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Get the lease's most recently due invoice.
     *
     * @return HasOne<Invoice, $this>
     */
    public function currentInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->latestOfMany('due_date');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<Concern, $this>
     */
    public function concerns(): HasMany
    {
        return $this->hasMany(Concern::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'billing_timing' => BillingTiming::class,
            'status' => LeaseStatus::class,
        ];
    }
}
