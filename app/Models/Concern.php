<?php

namespace App\Models;

use App\Enums\ConcernCategory;
use App\Enums\ConcernPriority;
use App\Enums\ConcernStatus;
use Database\Factories\ConcernFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $lease_id
 * @property ConcernCategory $category
 * @property string $title
 * @property string $description
 * @property ConcernPriority $priority
 * @property ConcernStatus $status
 * @property string|null $photo_path
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lease $lease
 * @property-read Collection<int, ConcernUpdate> $updates
 */
#[Fillable(['lease_id', 'category', 'title', 'description', 'priority', 'status', 'photo_path', 'resolved_at'])]
class Concern extends Model
{
    /** @use HasFactory<ConcernFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Lease, $this>
     */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /**
     * @return HasMany<ConcernUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(ConcernUpdate::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ConcernCategory::class,
            'priority' => ConcernPriority::class,
            'status' => ConcernStatus::class,
            'resolved_at' => 'datetime',
        ];
    }
}
