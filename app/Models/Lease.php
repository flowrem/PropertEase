<?php

namespace App\Models;

use App\Enums\LeaseStatus;
use Database\Factories\LeaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $unit_id
 * @property int $tenant_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int $due_day
 * @property LeaseStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 * @property-read User $tenant
 * @property-read Collection<int, LeaseRent> $rents
 * @property-read Collection<int, ServiceSubscription> $serviceSubscriptions
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, Concern> $concerns
 */
#[Fillable(['unit_id', 'tenant_id', 'start_date', 'end_date', 'due_day', 'status'])]
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
            'status' => LeaseStatus::class,
        ];
    }
}
