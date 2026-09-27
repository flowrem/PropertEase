<?php

namespace App\Enums;

enum AmenityCategory: string
{
    case Furniture = 'furniture';
    case Appliances = 'appliances';
    case Utilities = 'utilities';
    case Bathroom = 'bathroom';
    case Safety = 'safety';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Furniture => 'Furniture',
            self::Appliances => 'Appliances',
            self::Utilities => 'Utilities',
            self::Bathroom => 'Bathroom',
            self::Safety => 'Safety',
            self::Other => 'Other',
        };
    }
}
