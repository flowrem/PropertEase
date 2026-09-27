<?php

namespace App\Models;

use Database\Factories\IssueTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A kind of problem a tenant can report, like "Leaking faucet". Platform
 * defaults (no team) are seeded for every landlord; a team can add its own,
 * the same way as amenities.
 *
 * @property int $id
 * @property int|null $team_id
 * @property string $name
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 */
#[Fillable(['team_id', 'name', 'is_active'])]
class IssueType extends Model
{
    /** @use HasFactory<IssueTypeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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

    public function isPlatformDefault(): bool
    {
        return $this->team_id === null;
    }

    /**
     * @param  Builder<IssueType>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Limit the query to the platform defaults plus the given team's own
     * issue types, the only ones that team's tenants may report.
     *
     * @param  Builder<IssueType>  $query
     */
    public function scopeAvailableTo(Builder $query, Team $team): void
    {
        $query->where(fn (Builder $available) => $available
            ->whereNull('team_id')
            ->orWhere('team_id', $team->id));
    }
}
