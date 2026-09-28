<?php

namespace App\Models;

use App\Enums\PropertyType;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $address_line
 * @property string $city
 * @property string $province
 * @property string $postal_code
 * @property string|null $map_url
 * @property string|null $region_code
 * @property string|null $province_code
 * @property string|null $city_code
 * @property PropertyType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Unit> $units
 * @property-read Collection<int, Announcement> $announcements
 * @property-read Region|null $psgcRegion
 * @property-read Province|null $psgcProvince
 * @property-read City|null $psgcCity
 */
#[Fillable(['team_id', 'name', 'address_line', 'city', 'province', 'region_code', 'province_code', 'city_code', 'postal_code', 'map_url', 'type'])]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Each loaded unit gets this property set as its parent, since a unit's
     * capacity depends on the property type.
     *
     * @return HasMany<Unit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->chaperone();
    }

    /**
     * @return HasMany<Announcement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    /**
     * The PSGC region picked for the address. Named apart from the typed
     * `city` and `province` columns, which stay for display.
     *
     * @return BelongsTo<Region, $this>
     */
    public function psgcRegion(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_code');
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function psgcProvince(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function psgcCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_code');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PropertyType::class,
        ];
    }
}
