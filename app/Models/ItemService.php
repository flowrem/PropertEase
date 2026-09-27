<?php

namespace App\Models;

use App\Enums\ItemServiceAction;
use Database\Factories\ItemServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One repair, replacement or inspection of a unit item, recorded when a
 * tenant's report about it is resolved or logged by hand.
 *
 * @property int $id
 * @property int $unit_item_id
 * @property int|null $concern_id
 * @property ItemServiceAction $action
 * @property Carbon $performed_at
 * @property string|null $cost
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read UnitItem $unitItem
 * @property-read Concern|null $concern
 * @property-read User|null $recorder
 */
#[Fillable(['concern_id', 'action', 'performed_at', 'cost', 'notes', 'recorded_by'])]
class ItemService extends Model
{
    /** @use HasFactory<ItemServiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ItemServiceAction::class,
            'performed_at' => 'date',
            'cost' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<UnitItem, $this>
     */
    public function unitItem(): BelongsTo
    {
        return $this->belongsTo(UnitItem::class);
    }

    /**
     * @return BelongsTo<Concern, $this>
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(Concern::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
