<?php

namespace App\Models;

use App\Enums\ItemServiceAction;
use App\Enums\UnitItemType;
use Database\Factories\UnitItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * @property-read Collection<int, ItemService> $services
 * @property-read int|null $recent_fixes_count
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
     * Repairs, replacements and inspections of this item, newest first.
     *
     * @return HasMany<ItemService, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(ItemService::class)->latest('performed_at')->latest('id');
    }

    /**
     * Count each item's recent repairs and replacements alongside it, as
     * `recent_fixes_count`, for spotting items that keep breaking.
     *
     * @param  Builder<UnitItem>  $query
     */
    public function scopeWithRecentFixCount(Builder $query): void
    {
        $query->withCount(['services as recent_fixes_count' => fn (Builder $services) => $services
            ->whereIn('action', ItemServiceAction::fixValues())
            ->where('performed_at', '>=', today()->subMonths((int) config('occuplace.items.repeated_problems.months')))]);
    }

    /**
     * Whether the item was repaired or replaced often enough lately to
     * point at a deeper problem (3 or more times in the last 6 months, by
     * default). Needs withRecentFixCount() on the query.
     */
    public function hasRepeatedProblems(): bool
    {
        return (int) $this->recent_fixes_count >= (int) config('occuplace.items.repeated_problems.count');
    }

    /**
     * The item's repairs and replacements in words, like "Replaced 2 times
     * and repaired once, last on Sep 12, 2026", or null when it has none.
     * Uses the loaded services.
     */
    public function fixSummary(): ?string
    {
        $fixes = $this->services->filter(fn (ItemService $service): bool => $service->action->isFix());

        if ($fixes->isEmpty()) {
            return null;
        }

        $counts = collect([ItemServiceAction::Replaced, ItemServiceAction::Repaired])
            ->map(fn (ItemServiceAction $action): array => [$action, $fixes->where('action', $action)->count()])
            ->filter(fn (array $count): bool => $count[1] > 0)
            ->values()
            ->map(fn (array $count, int $index): string => trans_choice(
                $index === 0 ? ':action once|:action :count times' : ':action_lower once|:action_lower :count times',
                $count[1],
                ['action' => $count[0]->label(), 'action_lower' => mb_strtolower($count[0]->label())],
            ));

        return __(':counts, last on :date', [
            'counts' => $counts->join(', ', ' and '),
            'date' => $fixes->max('performed_at')->format('M j, Y'),
        ]);
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
