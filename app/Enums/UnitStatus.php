<?php

namespace App\Enums;

enum UnitStatus: string
{
    case Vacant = 'vacant';
    case Occupied = 'occupied';
    case UnderMaintenance = 'under_maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Vacant => 'Vacant',
            self::Occupied => 'Occupied',
            self::UnderMaintenance => 'Under Maintenance',
        };
    }

    /**
     * Get the badge color used to represent this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Vacant => 'lime',
            self::Occupied => 'blue',
            self::UnderMaintenance => 'amber',
        };
    }
}
