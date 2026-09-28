<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PSGC region, like "Region IV-A (CALABARZON)". Seeded; never edited in the app.
 *
 * @property string $code
 * @property string $name
 * @property-read Collection<int, Province> $provinces
 * @property-read Collection<int, City> $cities
 */
class Region extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /**
     * @return HasMany<Province, $this>
     */
    public function provinces(): HasMany
    {
        return $this->hasMany(Province::class, 'region_code');
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class, 'region_code');
    }

    /**
     * Metro Manila has no provinces; its cities sit directly under the region.
     */
    public function isMetroManila(): bool
    {
        return $this->provinces()->doesntExist();
    }
}
