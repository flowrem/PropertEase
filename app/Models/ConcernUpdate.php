<?php

namespace App\Models;

use App\Enums\ConcernStatus;
use Database\Factories\ConcernUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $concern_id
 * @property int $author_id
 * @property string $message
 * @property ConcernStatus|null $new_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Concern $concern
 * @property-read User $author
 */
#[Fillable(['concern_id', 'author_id', 'message', 'new_status'])]
class ConcernUpdate extends Model
{
    /** @use HasFactory<ConcernUpdateFactory> */
    use HasFactory;

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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'new_status' => ConcernStatus::class,
        ];
    }
}
