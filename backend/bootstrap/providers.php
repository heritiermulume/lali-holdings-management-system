<?php

use App\Modules\AgribusinessParkKisegese\AgribusinessParkKisegeseServiceProvider;
use App\Modules\HeavyEquipment\HeavyEquipmentServiceProvider;
use App\Modules\Kams\KamsServiceProvider;
use App\Modules\KibitiAgribusiness\KibitiAgribusinessServiceProvider;
use App\Modules\LaliTv\LaliTvServiceProvider;
use App\Modules\MachineryFleet\MachineryFleetServiceProvider;
use App\Modules\PropertiesMagomeni\PropertiesMagomeniServiceProvider;
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
    MachineryFleetServiceProvider::class,
    KibitiAgribusinessServiceProvider::class,
    PropertiesMagomeniServiceProvider::class,
    AgribusinessParkKisegeseServiceProvider::class,
];
