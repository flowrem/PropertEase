<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::PartiallyPaid => 'Partially Paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
        };
    }

    /**
     * Get the badge color used to represent this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'zinc',
            self::PartiallyPaid => 'amber',
            self::Paid => 'lime',
            self::Overdue => 'red',
        };
    }
}
