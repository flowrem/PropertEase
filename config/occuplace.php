<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Unit Limits
    |--------------------------------------------------------------------------
    |
    | Bounds on what a landlord can enter for a unit, and the figures used to
    | work out how many tenants a unit can hold from its floor area.
    |
    | The minimum room sizes follow the National Building Code (PD 1096)
    | minimums for a habitable room and a bathroom. A unit with bedrooms also
    | keeps a common area for its kitchen and living space. The area per
    | tenant and tenants per bedroom are placeholders to confirm before they
    | are cited anywhere.
    |
    */

    'units' => [

        'floor_area' => ['min' => 6, 'max' => 70],

        'bedrooms' => ['min' => 0, 'max' => 10],

        'bathrooms' => ['min' => 0, 'max' => 10],

        'minimum_bedroom_area' => 6,

        'minimum_bathroom_area' => 1.2,

        'common_area' => 6,

        'rent' => ['min' => 500, 'max' => 200000],

        'amenity_quantity' => ['max' => 20],

        /*
         * The share of a unit's sleeping area (its floor area less the
         * common area and bathrooms) that beds may cover. The rest is left
         * for walking space, cabinets and doors. A judgment call, not a
         * code figure: confirm it before citing it.
         */
        'bed_floor_coverage' => 0.5,

        'name_max_length' => 40,

        'highest_floor' => 50,

        'max_capacity' => 20,

        'area_per_tenant' => [
            'apartment' => 6,
            'condominium' => 6,
            'rental_home' => 6,
            'dormitory' => 4,
            'boarding_house' => 4,
        ],

        'tenants_per_bedroom' => [
            'apartment' => 2,
            'condominium' => 2,
            'rental_home' => 2,
            'dormitory' => 4,
            'boarding_house' => 4,
        ],

    ],

];
