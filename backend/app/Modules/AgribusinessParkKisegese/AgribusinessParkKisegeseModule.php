<?php

namespace App\Modules\AgribusinessParkKisegese;

use App\Modules\Support\StubModule;

class AgribusinessParkKisegeseModule extends StubModule
{
    public function slug(): string
    {
        return 'agribusiness-park-kisegese';
    }

    public function name(): array
    {
        return [
            'en' => 'Lali Agribusiness Park - Kisegese',
            'fr' => 'Lali Agribusiness Park - Kisegese',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Agribusiness park operations and tenancy at Kisegese.',
            'fr' => 'Parc agribusiness et gestion des occupants à Kisegese.',
        ];
    }
}
