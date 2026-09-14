<?php

namespace App\Modules\Support;

use App\Core\Contracts\BusinessModule;
use App\Core\Support\PermissionCatalog;

abstract class StubModule implements BusinessModule
{
    abstract public function slug(): string;

    abstract public function name(): array;

    abstract public function description(): array;

    public function version(): string
    {
        return '0.1.0';
    }

    public function permissions(): array
    {
        return PermissionCatalog::forModule($this->slug());
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
