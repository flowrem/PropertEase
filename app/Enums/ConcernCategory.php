<?php

namespace App\Enums;

enum ConcernCategory: string
{
    case Maintenance = 'maintenance';
    case Complaint = 'complaint';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => 'Maintenance',
            self::Complaint => 'Complaint',
        };
    }

    /**
     * Get the badge color used to represent this category.
     */
    public function color(): string
    {
        return match ($this) {
            self::Maintenance => 'blue',
            self::Complaint => 'amber',
        };
    }
}
