<?php

namespace App\Modules\PropertiesMagomeni;

use App\Modules\Support\StubModule;

class PropertiesMagomeniModule extends StubModule
{
    public function slug(): string
    {
        return 'properties-magomeni';
    }

    public function name(): array
    {
        return [
            'en' => 'Lali Properties / Magomeni',
            'fr' => 'Lali Properties / Magomeni',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Property and rental management for Magomeni.',
            'fr' => 'Gestion immobilière et locative à Magomeni.',
        ];
    }
}
