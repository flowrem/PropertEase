<?php

namespace App\Models;

use App\Enums\ItemCondition;
use Database\Factories\ConditionCheckItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The condition one unit item was found in on one check.
 *
 * @property int $id
 * @property int $condition_check_id
 * @property int $unit_item_id
 * @property ItemCondition $condition
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ConditionCheck $check
 * @property-read UnitItem $unitItem
 */
#[Fillable(['unit_item_id', 'condition', 'remarks'])]
class ConditionCheckItem extends Model
{
    /** @use HasFactory<ConditionCheckItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'condition' => ItemCondition::class,
        ];
    }

    /**
     * @return BelongsTo<ConditionCheck, $this>
     */
    public function check(): BelongsTo
    {
        return $this->belongsTo(ConditionCheck::class, 'condition_check_id');
    }

    /**
     * @return BelongsTo<UnitItem, $this>
     */
    public function unitItem(): BelongsTo
    {
        return $this->belongsTo(UnitItem::class);
    }
}
