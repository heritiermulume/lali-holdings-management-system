<?php

namespace App\Modules\LaliTv;

use App\Modules\Support\StubModule;

class LaliTvModule extends StubModule
{
    public function slug(): string
    {
        return 'lali-tv';
    }

    public function name(): array
    {
        return [
            'en' => 'LALI TV',
            'fr' => 'LALI TV',
        ];
    }

    public function description(): array
    {
        return [
            'en' => 'Media programming, advertising and broadcast operations.',
            'fr' => 'Programmation, publicité et opérations de diffusion.',
        ];
    }
}
