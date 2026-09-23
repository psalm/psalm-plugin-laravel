<?php

declare(strict_types=1);

namespace Fx;

/**
 * A magic-property receiver with no `@property` annotation for `missing`/`nope`, so Psalm's
 * `__get`/`__set` handling (armed by the fixture's `sealAllProperties="true"`) has nothing to
 * resolve either the fetch or the assignment against (#1545).
 */
final class Magic
{
    public function __get(string $name): mixed
    {
        return $name;
    }

    public function __set(string $name, mixed $value): void
    {
        unset($name, $value);
    }
}
