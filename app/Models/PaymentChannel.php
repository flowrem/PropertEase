<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\PaymentChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $team_id
 * @property PaymentMethod $method
 * @property string $account_name
 * @property string|null $account_number
 * @property string|null $bank_name
 * @property string|null $qr_path
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 */
#[Fillable(['team_id', 'method', 'account_name', 'account_number', 'bank_name', 'qr_path', 'is_active'])]
class PaymentChannel extends Model
{
    /** @use HasFactory<PaymentChannelFactory> */
    use HasFactory;

    /**
     * Methods a landlord can offer for online reservation downpayments.
     *
     * @return array<int, PaymentMethod>
     */
    public static function onlineMethods(): array
    {
        return [PaymentMethod::Gcash, PaymentMethod::BankTransfer];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
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

    /**
     * @param  Builder<PaymentChannel>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function qrUrl(): ?string
    {
        return $this->qr_path
            ? Storage::disk(config('filesystems.media_disk'))->url($this->qr_path)
            : null;
    }
}
