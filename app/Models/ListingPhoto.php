<?php

namespace App\Models;

use Database\Factories\ListingPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $unit_listing_id
 * @property string $path
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read UnitListing $listing
 */
#[Fillable(['unit_listing_id', 'path', 'sort_order'])]
class ListingPhoto extends Model
{
    /** @use HasFactory<ListingPhotoFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<UnitListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(UnitListing::class, 'unit_listing_id');
    }

    public function url(): string
    {
        return Storage::disk(config('filesystems.media_disk'))->url($this->path);
    }
}
