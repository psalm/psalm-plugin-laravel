<?php

declare(strict_types=1);

namespace Fx;

/**
 * Declares `secret` but keeps it protected, so a template assigning it from outside hits `__set` while
 * `propertyExists()` is true: the `UndefinedMagicPropertyAssignment` twin stays silent even for a
 * variable receiver, which leaves the synthesized `UndefinedThisPropertyAssignment` the only diagnostic (#2129).
 */
final class Guarded
{
    protected string $secret = '';

    public function __get(string $name): mixed
    {
        return $name;
    }

    public function __set(string $name, mixed $value): void
    {
        unset($name, $value);
    }
}
