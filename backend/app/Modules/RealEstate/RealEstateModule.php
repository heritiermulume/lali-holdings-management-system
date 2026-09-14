<?php

namespace App\Modules\RealEstate;

use App\Modules\Support\StubModule;

class RealEstateModule extends StubModule
{
    public function slug(): string
    {
        return 'real-estate';
    }

    public function name(): array
    {
        return [
            'en' => 'LALI Apartments / Real Estate',
            'fr' => 'LALI Apartments / Immobilier',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Property portfolio, leases and tenant operations.',
            'fr' => 'Portefeuille immobilier, baux et gestion locative.',
        ];
    }
}
