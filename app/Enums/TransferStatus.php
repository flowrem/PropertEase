<?php

namespace App\Enums;

enum TransferStatus: string
{
    /** Asked by the tenant, waiting for the landlord. */
    case Pending = 'pending';

    /** Approved: the new unit's slot is held until the move. */
    case Approved = 'approved';

    case Rejected = 'rejected';

    /** Withdrawn by the tenant, or cancelled by the landlord after approving. */
    case Cancelled = 'cancelled';

    /** The tenant moved. */
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'blue',
            self::Rejected => 'red',
            self::Cancelled => 'zinc',
            self::Completed => 'lime',
        };
    }

    /**
     * Whether the request is still in progress, so the tenant cannot open
     * another one.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
