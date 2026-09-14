<?php

namespace App\Modules\RiceMills;

use App\Modules\Support\StubModule;

class RiceMillsModule extends StubModule
{
    public function slug(): string
    {
        return 'rice-mills';
    }

    public function name(): array
    {
        return [
            'en' => 'LALI Miembeni Rice Mills',
            'fr' => 'LALI Miembeni Rice Mills',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Production, inventory and distribution for rice milling operations.',
            'fr' => 'Production, stocks et distribution de la rizerie.',
        ];
    }
}
