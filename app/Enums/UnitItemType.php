<?php

namespace App\Enums;

enum UnitItemType: string
{
    case Lighting = 'lighting';
    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Fixture = 'fixture';
    case Appliance = 'appliance';
    case Furniture = 'furniture';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lighting => 'Lighting',
            self::Plumbing => 'Plumbing',
            self::Electrical => 'Electrical',
            self::Fixture => 'Fixture',
            self::Appliance => 'Appliance',
            self::Furniture => 'Furniture',
            self::Other => 'Other',
        };
    }

    /**
     * The item type an amenity of the given category becomes when the
     * inventory is built from a unit's amenities.
     */
    public static function forAmenityCategory(AmenityCategory $category): self
    {
        return match ($category) {
            AmenityCategory::Furniture => self::Furniture,
            AmenityCategory::Appliances => self::Appliance,
            AmenityCategory::Bathroom => self::Plumbing,
            AmenityCategory::Safety => self::Fixture,
            AmenityCategory::Utilities, AmenityCategory::Other => self::Other,
        };
    }
}
