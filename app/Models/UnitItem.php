<?php

namespace App\Models;

use App\Enums\UnitItemType;
use Database\Factories\UnitItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One physical thing in a unit that is checked at move-in and move-out,
 * like "Ceiling light, bedroom" or "Double deck 2". An item that appears on
 * a check is never deleted; removing it only hides it from future checks,
 * so past checks keep their history.
 *
 * @property int $id
 * @property int $unit_id
 * @property int|null $amenity_id
 * @property string $name
 * @property UnitItemType $item_type
 * @property Carbon|null $installed_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 * @property-read Amenity|null $amenity
 */
#[Fillable(['amenity_id', 'name', 'item_type', 'installed_at'])]
class UnitItem extends Model
{
    /** @use HasFactory<UnitItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => UnitItemType::class,
            'installed_at' => 'date',
            'removed_at' => 'datetime',
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
     * @return BelongsTo<Amenity, $this>
     */
    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class);
    }

    /**
     * @return HasMany<ConditionCheckItem, $this>
     */
    public function checkItems(): HasMany
    {
        return $this->hasMany(ConditionCheckItem::class);
    }

    /**
     * Items still in the unit, the ones a new check covers.
     *
     * @param  Builder<UnitItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /**
     * Take the item out of future checks. One that no check mentions yet is
     * simply deleted; one that has been checked stays for its history.
     */
    public function remove(): void
    {
        if ($this->checkItems()->exists()) {
            $this->forceFill(['removed_at' => now()])->save();

            return;
        }

        $this->delete();
    }
}
