<?php

namespace App\Core\Contracts;

interface BusinessModule
{
    public function slug(): string;

    public function name(): array;

    public function description(): array;

    public function version(): string;

    public function permissions(): array;

    public function isAvailable(): bool;
}
