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
    | minimums for a habitable room and a bathroom. The area per tenant is a
    | placeholder to confirm before it is cited anywhere.
    |
    */

    'units' => [

        'floor_area' => ['min' => 6, 'max' => 70],

        'bedrooms' => ['min' => 0, 'max' => 10],

        'bathrooms' => ['min' => 0, 'max' => 10],

        'minimum_bedroom_area' => 6,

        'minimum_bathroom_area' => 1.2,

        'rent' => ['min' => 500, 'max' => 200000],

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

    ],

];
