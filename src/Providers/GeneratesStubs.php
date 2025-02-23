<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

interface GeneratesStubs
{
    public static function generateStubFile(): void;

    public static function getStubFileLocation(): string;
}
