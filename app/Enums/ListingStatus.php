<?php

namespace App\Enums;

enum ListingStatus: string
{
    case Draft = 'draft';
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Unlisted = 'unlisted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Unlisted => 'Unlisted',
        };
    }

    /**
     * Get the badge color used to represent this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::PendingReview => 'amber',
            self::Approved => 'lime',
            self::Rejected => 'red',
            self::Unlisted => 'zinc',
        };
    }
}
