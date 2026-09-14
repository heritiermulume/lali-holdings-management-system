<?php

namespace App\Modules\HeavyEquipment;

use App\Modules\Support\StubModule;

class HeavyEquipmentModule extends StubModule
{
    public function slug(): string
    {
        return 'heavy-equipment';
    }

    public function name(): array
    {
        return [
            'en' => 'LALI Heavy Equipment',
            'fr' => 'LALI Heavy Equipment',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Fleet, rentals and maintenance for heavy equipment operations.',
            'fr' => 'Parc, locations et maintenance des équipements lourds.',
        ];
    }
}
