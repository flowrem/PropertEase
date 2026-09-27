<?php

namespace App\Models;

use App\Enums\AmenityCategory;
use Database\Factories\AmenityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Something a unit comes with, like a bed, a fan or Wi-Fi. Platform
 * defaults (no team) are seeded for every landlord; a team can add its own.
 *
 * `sleeps` is how many people one of this amenity sleeps, and decides a
 * unit's capacity. It is deliberately not fillable: only the seeded platform
 * beds set it, so a landlord can never invent a "bed" that sleeps ten.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string $name
 * @property AmenityCategory $category
 * @property int $sleeps
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 */
#[Fillable(['team_id', 'name', 'category', 'is_active'])]
class Amenity extends Model
{
    /** @use HasFactory<AmenityFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AmenityCategory::class,
            'sleeps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Whether this is one of the platform defaults every landlord sees.
     */
    public function isPlatformDefault(): bool
    {
        return $this->team_id === null;
    }

    /**
     * Group amenities under their category labels, in the order the
     * category enum lists them (Furniture first), for checkbox lists.
     *
     * @param  EloquentCollection<int, Amenity>  $amenities
     * @return Collection<string, EloquentCollection<int, Amenity>>
     */
    public static function groupByCategory(EloquentCollection $amenities): Collection
    {
        $categoryOrder = array_map(fn (AmenityCategory $category): string => $category->label(), AmenityCategory::cases());

        return $amenities
            ->groupBy(fn (Amenity $amenity): string => $amenity->category->label())
            ->sortBy(fn (EloquentCollection $group, string $label): int => (int) array_search($label, $categoryOrder, true));
    }

    /**
     * @param  Builder<Amenity>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Limit the query to the platform defaults plus the given team's own
     * amenities, the only ones that team may see or put on its units.
     *
     * @param  Builder<Amenity>  $query
     */
    public function scopeAvailableTo(Builder $query, Team $team): void
    {
        $query->where(fn (Builder $available) => $available
            ->whereNull('team_id')
            ->orWhere('team_id', $team->id));
    }
}
