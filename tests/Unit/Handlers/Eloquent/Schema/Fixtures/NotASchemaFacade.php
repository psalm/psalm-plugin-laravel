<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures;

/**
 * Test fixture: a class that exposes a static connection() method but is not
 * (and does not extend) the Illuminate Schema facade. Used to verify the
 * isSchemaClass() gate rejects lookalike connection() calls.
 */
final class NotASchemaFacade
{
    public static function connection(): self
    {
        return new self();
    }
}
