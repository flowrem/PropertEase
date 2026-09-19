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
}
