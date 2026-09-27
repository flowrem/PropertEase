<?php

namespace App\Models;

use App\Enums\ConditionCheckKind;
use App\Enums\ItemCondition;
use Database\Factories\ConditionCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An inspection of a unit's items, at move-in, move-out or any time.
 *
 * A move-in check is recorded before the tenant is assigned, and the lease
 * created for them claims it (`lease_id`), so each incoming tenant has one
 * of their own to acknowledge. `lease_id` and `tenant_acknowledged_at` are
 * not fillable: only claiming and acknowledging set them.
 *
 * @property int $id
 * @property int $unit_id
 * @property int|null $lease_id
 * @property ConditionCheckKind $kind
 * @property int|null $checked_by
 * @property Carbon $checked_at
 * @property string|null $notes
 * @property Carbon|null $tenant_acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 * @property-read Lease|null $lease
 * @property-read User|null $checker
 * @property-read Collection<int, ConditionCheckItem> $items
 */
#[Fillable(['kind', 'checked_by', 'checked_at', 'notes'])]
class ConditionCheck extends Model
{
    /** @use HasFactory<ConditionCheckFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ConditionCheckKind::class,
            'checked_at' => 'datetime',
            'tenant_acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
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
    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    /**
     * @return HasMany<ConditionCheckItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ConditionCheckItem::class);
    }

    /**
     * How many items were found not working or missing, which stops a
     * tenant moving in unless the landlord proceeds anyway.
     */
    public function blockingItemCount(): int
    {
        if ($this->relationLoaded('items')) {
            return $this->items->filter(fn (ConditionCheckItem $item): bool => $item->condition->blocksMoveIn())->count();
        }

        return $this->items()->whereIn('condition', ItemCondition::blockingValues())->count();
    }

    /**
     * Tie this move-in check to the lease of the tenant it was recorded for.
     */
    public function claimFor(Lease $lease): void
    {
        $this->forceFill(['lease_id' => $lease->id])->save();
    }

    /**
     * Record that the tenant agreed this is the unit's condition. Only the
     * first acknowledgment counts.
     */
    public function acknowledge(): void
    {
        if ($this->tenant_acknowledged_at !== null) {
            return;
        }

        $this->forceFill(['tenant_acknowledged_at' => now()])->save();
    }
}
