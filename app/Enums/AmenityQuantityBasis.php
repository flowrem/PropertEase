<?php

namespace App\Enums;

/**
 * What an amenity's quantity is limited by, so a unit can only list as many
 * of something as it could realistically have.
 */
enum AmenityQuantityBasis: string
{
    /** Either the unit has it or it doesn't, like Wi-Fi. */
    case Single = 'single';

    /** A fixed number per unit, like one refrigerator. */
    case PerUnit = 'per_unit';

    /** A number per room: each bedroom plus the living area (a studio is one room). */
    case PerRoom = 'per_room';

    /** A number per bathroom, counting a shared bathroom as one. */
    case PerBathroom = 'per_bathroom';

    /** A number per tenant the unit can hold. */
    case PerTenant = 'per_tenant';

    /** Beds: as many as fit in the floor space and the tenants it allows. */
    case FloorSpace = 'floor_space';

    /**
     * The most of an amenity a unit can have under this basis. Beds depend
     * on the other beds ticked too, so the unit form works theirs out.
     */
    public function maxQuantity(int $per, int $bedrooms, int $bathrooms, int $tenants): int
    {
        return match ($this) {
            self::Single => 1,
            self::PerUnit, self::FloorSpace => $per,
            self::PerRoom => $per * ($bedrooms + 1),
            self::PerBathroom => $per * max($bathrooms, 1),
            self::PerTenant => $per * max($tenants, 1),
        };
    }

    /**
     * The rule in words, for the message shown when a quantity is too high.
     */
    public function describe(int $per): string
    {
        return match ($this) {
            self::Single => __('the unit either has it or not'),
            self::PerUnit => trans_choice(':count per unit|:count per unit', $per),
            self::PerRoom => trans_choice(':count per bedroom, plus :count for the living area|:count per bedroom, plus :count for the living area', $per),
            self::PerBathroom => trans_choice(':count per bathroom|:count per bathroom', $per),
            self::PerTenant => trans_choice(':count per tenant the unit fits|:count per tenant the unit fits', $per),
            self::FloorSpace => __('as many as the floor space and tenants allow'),
        };
    }
}
