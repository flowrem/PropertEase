<?php

namespace App\Models;

use Database\Factories\ContractTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A landlord's standard contract terms. Each new lease's contract is made
 * from these and keeps its own copy, so editing them never changes a
 * contract already made.
 *
 * @property int $id
 * @property int $team_id
 * @property int $advance_months
 * @property int $deposit_months
 * @property int $minimum_stay_months_short
 * @property int $minimum_stay_months_long
 * @property int $notice_days
 * @property string|null $late_fee
 * @property string|null $house_rules
 * @property string|null $additional_terms
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable([
    'advance_months', 'deposit_months', 'minimum_stay_months_short', 'minimum_stay_months_long',
    'notice_days', 'late_fee', 'house_rules', 'additional_terms',
])]
class ContractTemplate extends Model
{
    /** @use HasFactory<ContractTemplateFactory> */
    use HasFactory;

    /**
     * The defaults a landlord starts from, so a contract can be made before
     * they ever open the Contract terms page.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'advance_months' => 1,
        'deposit_months' => 2,
        'minimum_stay_months_short' => 1,
        'minimum_stay_months_long' => 12,
        'notice_days' => 30,
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'advance_months' => 'integer',
            'deposit_months' => 'integer',
            'minimum_stay_months_short' => 'integer',
            'minimum_stay_months_long' => 'integer',
            'notice_days' => 'integer',
            'late_fee' => 'decimal:2',
        ];
    }
}
