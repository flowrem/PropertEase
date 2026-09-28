<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Enums\StayType;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $code
 * @property int|null $unit_listing_id
 * @property int $unit_id
 * @property int $team_id
 * @property string $desired_username
 * @property string $email
 * @property string $first_name
 * @property string $last_name
 * @property string|null $contact_number
 * @property int $age
 * @property string $address
 * @property StayType|null $stay_type
 * @property string $valid_id_path
 * @property string|null $downpayment_amount
 * @property int|null $payment_channel_id
 * @property PaymentMethod|null $downpayment_method
 * @property string|null $downpayment_reference
 * @property string|null $downpayment_proof_path
 * @property Carbon|null $downpayment_submitted_at
 * @property ReservationStatus $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $expired_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $rejection_reason
 * @property int|null $tenant_user_id
 * @property Carbon $consented_at
 * @property Carbon|null $downpayment_confirmed_at
 * @property int|null $downpayment_confirmed_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $files_pruned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read UnitListing|null $listing
 * @property-read Unit $unit
 * @property-read Team $team
 * @property-read PaymentChannel|null $paymentChannel
 */
#[Fillable([
    'code', 'unit_listing_id', 'unit_id', 'team_id', 'desired_username', 'email', 'first_name', 'last_name',
    'contact_number', 'age', 'address', 'stay_type', 'valid_id_path', 'downpayment_amount', 'payment_channel_id',
    'downpayment_method', 'downpayment_reference', 'downpayment_proof_path', 'consented_at',
])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /**
     * Characters used in public reference codes. Ambiguous ones (0/O, 1/I) are left out.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'downpayment_method' => PaymentMethod::class,
            'stay_type' => StayType::class,
            'reviewed_at' => 'datetime',
            'consented_at' => 'datetime',
            'downpayment_confirmed_at' => 'datetime',
            'downpayment_submitted_at' => 'datetime',
            'expires_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'files_pruned_at' => 'datetime',
        ];
    }

    /**
     * Reservations holding a slot on their unit: confirmed ones, and reserved
     * ones whose downpayment deadline has not passed. A reserved one stops
     * holding the moment its deadline passes, even before the hourly job
     * marks it expired, since that job can be missed while the server sleeps.
     *
     * @param  Builder<Reservation>  $query
     */
    public function scopeHoldingSlot(Builder $query): void
    {
        [$sql, $bindings] = self::holdingSlotSql();

        $query->whereRaw($sql, $bindings);
    }

    /**
     * The same rule as scopeHoldingSlot(), as raw SQL on `reservations` for
     * the capacity subqueries in Unit::scopeHasRoom().
     *
     * @return array{0: literal-string, 1: array<int, mixed>}
     */
    public static function holdingSlotSql(): array
    {
        return [
            '(reservations.status = ? or (reservations.status = ? and reservations.expires_at > ?))',
            [ReservationStatus::Confirmed->value, ReservationStatus::Reserved->value, now()],
        ];
    }

    /**
     * Whether this reservation holds a slot right now (see scopeHoldingSlot()).
     */
    public function holdsSlot(): bool
    {
        return $this->status === ReservationStatus::Confirmed
            || ($this->status === ReservationStatus::Reserved && ! $this->isPastDeadline());
    }

    /**
     * Whether a reserved reservation's downpayment deadline has passed.
     */
    public function isPastDeadline(): bool
    {
        return $this->status === ReservationStatus::Reserved
            && ($this->expires_at === null || ! $this->expires_at->isFuture());
    }

    /**
     * Whether the applicant can still send their downpayment proof.
     */
    public function acceptsDownpayment(): bool
    {
        return $this->status === ReservationStatus::Reserved && ! $this->isPastDeadline();
    }

    /**
     * Generate a short, unique, hard-to-guess public reference.
     */
    public static function generateCode(): string
    {
        do {
            $code = collect(range(1, 8))
                ->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
                ->implode('');
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * @return BelongsTo<UnitListing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(UnitListing::class, 'unit_listing_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<PaymentChannel, $this>
     */
    public function paymentChannel(): BelongsTo
    {
        return $this->belongsTo(PaymentChannel::class);
    }

    /**
     * The tenant account created when this reservation was approved.
     *
     * @return BelongsTo<User, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Whether the ID and payment proof are still stored (they are deleted
     * some time after a rejected or cancelled reservation).
     */
    public function hasFiles(): bool
    {
        return $this->files_pruned_at === null;
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ReservationStatus::Pending->value);
    }

    public function fullName(): string
    {
        return Str::squish($this->first_name.' '.$this->last_name);
    }
}
