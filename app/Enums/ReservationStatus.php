<?php

namespace App\Enums;

enum ReservationStatus: string
{
    /** Sent by the applicant, waiting for the landlord. */
    case Pending = 'pending';

    /** Accepted: the unit is held until the downpayment deadline. */
    case Reserved = 'reserved';

    /** Downpayment confirmed: the tenant account exists and the unit is theirs. */
    case Confirmed = 'confirmed';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    /** No downpayment by the deadline, so the unit was released. */
    case Expired = 'expired';

    /** The tenant moved in. */
    case Fulfilled = 'fulfilled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Reserved => 'Reserved',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Fulfilled => 'Fulfilled',
        };
    }

    /**
     * Get the badge color used to represent this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Reserved => 'sky',
            self::Confirmed => 'blue',
            self::Rejected => 'red',
            self::Cancelled, self::Expired => 'zinc',
            self::Fulfilled => 'lime',
        };
    }
}
