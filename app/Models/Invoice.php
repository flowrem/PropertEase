<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lease_id
 * @property Carbon $billing_start
 * @property Carbon $billing_end
 * @property string $rent_amount
 * @property string $service_amount
 * @property string $penalty_amount
 * @property string $total_amount
 * @property Carbon $due_date
 * @property InvoiceStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lease $lease
 * @property-read Collection<int, Payment> $payments
 */
#[Fillable(['lease_id', 'billing_start', 'billing_end', 'rent_amount', 'service_amount', 'penalty_amount', 'total_amount', 'due_date', 'status'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Scope to invoices that still need to be paid, in whole or in part.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [
            InvoiceStatus::Unpaid,
            InvoiceStatus::PartiallyPaid,
            InvoiceStatus::Overdue,
        ]);
    }

    /**
     * How much of this invoice is still unpaid.
     */
    public function balanceDue(): float
    {
        return round((float) $this->total_amount - (float) $this->payments->sum('amount_paid'), 2);
    }

    /**
     * Whether this invoice is unpaid (or only partially paid) and its due
     * date has already passed, regardless of what its stored status says.
     */
    public function isPastDue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid], true)
            && $this->due_date->isBefore(today());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_start' => 'date',
            'billing_end' => 'date',
            'due_date' => 'date',
            'rent_amount' => 'decimal:2',
            'service_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
        ];
    }
}
