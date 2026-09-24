<?php

namespace App\Models;

use App\Enums\ListingStatus;
use Database\Factories\UnitListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $unit_id
 * @property string $title
 * @property string $description
 * @property string $contact_name
 * @property string $contact_phone
 * @property string $contact_email
 * @property string|null $downpayment_amount
 * @property ListingStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $reviewed_at
 * @property int|null $reviewed_by
 * @property string|null $rejection_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit $unit
 * @property-read User|null $reviewer
 * @property-read Collection<int, ListingPhoto> $photos
 */
#[Fillable(['unit_id', 'title', 'description', 'contact_name', 'contact_phone', 'contact_email', 'downpayment_amount'])]
class UnitListing extends Model
{
    /** @use HasFactory<UnitListingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<ListingPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ListingPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The team that owns this listing, resolved through its unit's property.
     */
    public function team(): Team
    {
        return $this->unit->property->team;
    }

    /**
     * @param  Builder<UnitListing>  $query
     */
    public function scopeForTeam(Builder $query, Team $team): void
    {
        $query->whereHas('unit.property', fn (Builder $property) => $property->where('team_id', $team->id));
    }
}
