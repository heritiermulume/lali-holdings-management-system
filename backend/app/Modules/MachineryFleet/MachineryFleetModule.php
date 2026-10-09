<?php

namespace App\Modules\MachineryFleet;

use App\Modules\Support\StubModule;

class MachineryFleetModule extends StubModule
{
    public function slug(): string
    {
        return 'machinery-fleet';
    }

    public function name(): array
    {
        return [
            'en' => 'Lali Machinery & Fleet',
            'fr' => 'Lali Machinery & Fleet',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Machinery pool, fleet operations and maintenance.',
            'fr' => 'Parc machines, flotte et maintenance.',
        ];
    }
}
