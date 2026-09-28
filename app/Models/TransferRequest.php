<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Database\Factories\TransferRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A tenant's request to move to another unit of the same landlord. The
 * status and everything the landlord decides are not fillable; the
 * transfer actions set them.
 *
 * @property int $id
 * @property int $team_id
 * @property int $lease_id
 * @property int $tenant_id
 * @property int $from_unit_id
 * @property int $to_unit_id
 * @property string $reason
 * @property Carbon $preferred_date
 * @property Carbon|null $move_date
 * @property TransferStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $decision_reason
 * @property int|null $new_lease_id
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Lease $lease
 * @property-read User $tenant
 * @property-read Unit $fromUnit
 * @property-read Unit $toUnit
 * @property-read User|null $reviewer
 * @property-read Lease|null $newLease
 */
#[Fillable(['team_id', 'lease_id', 'tenant_id', 'from_unit_id', 'to_unit_id', 'reason', 'preferred_date'])]
class TransferRequest extends Model
{
    /** @use HasFactory<TransferRequestFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'from_unit_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'to_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function newLease(): BelongsTo
    {
        return $this->belongsTo(Lease::class, 'new_lease_id');
    }

    /**
     * Requests still in progress (pending or approved).
     *
     * @param  Builder<TransferRequest>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [TransferStatus::Pending->value, TransferStatus::Approved->value]);
    }

    /**
     * Whether the move can be completed today: approved, and the move date
     * has come.
     */
    public function isDueToMove(): bool
    {
        return $this->status === TransferStatus::Approved
            && $this->move_date !== null
            && ! $this->move_date->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'preferred_date' => 'date',
            'move_date' => 'date',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
