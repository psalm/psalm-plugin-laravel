<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

/** @psalm-mutable */
interface GeneratesStubs
{
    /** @psalm-impure */
    public static function generateStubFile(): void;

    /** @psalm-impure */
    public static function getStubFileLocation(): string;
}
