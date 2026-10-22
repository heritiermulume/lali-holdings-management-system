<?php

return [
    'name' => 'LALI Holdings Management System',
    'short_name' => 'LHMS',
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'supported_locales' => ['fr', 'en', 'sw'],

    /*
    |--------------------------------------------------------------------------
    | Registered business modules
    |--------------------------------------------------------------------------
    |
    | Providers bootstrap routes and bindings. Module classes expose the
    | public contract consumed by LHMS Core (status, permissions, finance).
    |
    */
    'modules' => [
        'kams' => App\Modules\Kams\KamsModule::class,
        'heavy-equipment' => App\Modules\HeavyEquipment\HeavyEquipmentModule::class,
        'real-estate' => App\Modules\RealEstate\RealEstateModule::class,
        'rice-mills' => App\Modules\RiceMills\RiceMillsModule::class,
        'lali-tv' => App\Modules\LaliTv\LaliTvModule::class,
        'machinery-fleet' => App\Modules\MachineryFleet\MachineryFleetModule::class,
    ],
];
