<?php

namespace App\Modules\KibitiAgribusiness;

use App\Modules\Support\StubModule;

class KibitiAgribusinessModule extends StubModule
{
    public function slug(): string
    {
        return 'kibiti-agribusiness';
    }

    public function name(): array
    {
        return [
            'en' => 'Kibiti Agribusiness Project',
            'fr' => 'Kibiti Agribusiness Project',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Agribusiness operations and farm management in Kibiti.',
            'fr' => 'Opérations agribusiness et gestion agricole à Kibiti.',
        ];
    }
}
