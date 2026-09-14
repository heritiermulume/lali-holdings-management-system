<?php

use App\Modules\HeavyEquipment\HeavyEquipmentServiceProvider;
use App\Modules\Kams\KamsServiceProvider;
use App\Modules\LaliTv\LaliTvServiceProvider;
use App\Modules\RealEstate\RealEstateServiceProvider;
use App\Modules\RiceMills\RiceMillsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    KamsServiceProvider::class,
    HeavyEquipmentServiceProvider::class,
    RealEstateServiceProvider::class,
    RiceMillsServiceProvider::class,
    LaliTvServiceProvider::class,
];
