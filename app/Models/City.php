<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PSGC city or municipality. Its province is the one it lies in, or null
 * for Metro Manila's cities, Isabela City and the BARMM Special Geographic Area. Seeded;
 * never edited in the app.
 *
 * @property string $code
 * @property string $name
 * @property string|null $province_code
 * @property string $region_code
 * @property bool $is_city
 * @property-read Province|null $province
 * @property-read Region $region
 */
class City extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_code');
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_code');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_city' => 'boolean',
        ];
    }
}
