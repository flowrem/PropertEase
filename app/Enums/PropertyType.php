<?php

namespace App\Enums;

enum PropertyType: string
{
    case Apartment = 'apartment';
    case Dormitory = 'dormitory';
    case Condominium = 'condominium';
    case BoardingHouse = 'boarding_house';
    case RentalHome = 'rental_home';

    public function label(): string
    {
        return match ($this) {
            self::Apartment => 'Apartment',
            self::Dormitory => 'Dormitory',
            self::Condominium => 'Condominium',
            self::BoardingHouse => 'Boarding House',
            self::RentalHome => 'Rental Home',
        };
    }

    /**
     * Floor area, in square meters, each tenant needs in a unit of this type.
     */
    public function areaPerTenant(): float
    {
        return (float) config("occuplace.units.area_per_tenant.{$this->value}");
    }

    /**
     * How many tenants can share one bedroom in a unit of this type.
     */
    public function tenantsPerBedroom(): int
    {
        return (int) config("occuplace.units.tenants_per_bedroom.{$this->value}");
    }
}
