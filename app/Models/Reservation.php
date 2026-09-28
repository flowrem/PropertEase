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
use Illuminate\Support\Facades\URL;
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
    'contact_number', 'age', 'address', 'stay_type', 'valid_id_path', 'consented_at',
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
     * Session key listing the reservation codes this visitor has opened,
     * with the emailed link or by giving the code and email.
     */
    public const STATUS_ACCESS_SESSION_KEY = 'reservation_status_access';

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
     * ones that are within their downpayment deadline or whose applicant sent
     * the downpayment in time (the landlord just hasn't checked it yet). A
     * reserved one with nothing sent stops holding the moment its deadline
     * passes, even before the hourly job marks it expired, since that job
     * can be missed while the server sleeps.
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
            '(reservations.status = ? or (reservations.status = ?'
                .' and (reservations.expires_at > ? or reservations.downpayment_submitted_at is not null)))',
            [ReservationStatus::Confirmed->value, ReservationStatus::Reserved->value, now()],
        ];
    }

    /**
     * Reserved reservations whose deadline passed with no downpayment sent:
     * the ones the expiry job closes.
     *
     * @param  Builder<Reservation>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', ReservationStatus::Reserved->value)
            ->whereNull('downpayment_submitted_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Whether this reservation holds a slot right now (see scopeHoldingSlot()).
     */
    public function holdsSlot(): bool
    {
        return $this->status === ReservationStatus::Confirmed
            || ($this->status === ReservationStatus::Reserved && (! $this->isPastDeadline() || $this->hasDownpaymentSent()));
    }

    /**
     * The downpayment deadline as people here read it, like
     * "Sep 30, 2026, 5:00 PM", in the display timezone.
     */
    public function deadlineForDisplay(): ?string
    {
        return $this->expires_at?->timezone(config('occuplace.display_timezone'))->format('M j, Y, g:i A');
    }

    /**
     * A link to the applicant's status page that opens it without asking for
     * the code and email again. It never expires; the page itself only
     * shows what the reservation's state allows.
     */
    public function statusUrl(): string
    {
        return URL::signedRoute('reservations.status', ['code' => $this->code]);
    }

    public function hasDownpaymentSent(): bool
    {
        return $this->downpayment_submitted_at !== null;
    }

    /**
     * Whether the deadline passed with no downpayment sent (see scopeOverdue()).
     */
    public function isOverdue(): bool
    {
        return $this->isPastDeadline() && ! $this->hasDownpaymentSent();
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

    /**
     * Reservations waiting on the landlord: new ones to review, and reserved
     * ones whose downpayment arrived and needs checking.
     *
     * @param  Builder<Reservation>  $query
     */
    public function scopeNeedingLandlord(Builder $query): void
    {
        $query->where(fn (Builder $waiting) => $waiting
            ->where('status', ReservationStatus::Pending->value)
            ->orWhere(fn (Builder $sent) => $sent
                ->where('status', ReservationStatus::Reserved->value)
                ->whereNotNull('downpayment_submitted_at')));
    }

    public function fullName(): string
    {
        return Str::squish($this->first_name.' '.$this->last_name);
    }
}
