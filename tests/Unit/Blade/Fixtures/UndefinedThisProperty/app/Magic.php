<?php

declare(strict_types=1);

namespace Fx;

/**
 * A magic-property receiver with no `@property` annotation for `missing`/`nope`, so Psalm's
 * `__get`/`__set` handling (armed by the fixture's `sealAllProperties="true"`) has nothing to
 * resolve either the fetch or the assignment against (#1545). `make()` gives the template a
 * non-variable receiver (`Magic::make()->missing`): Psalm resolves no `$var_id` for it, which the
 * `__set` twin's emission depends on but the `__get` twin's does not (#1545 review).
 */
final class Magic
{
    public static function make(): self
    {
        return new self();
    }

    public function __get(string $name): mixed
    {
        return $name;
    }

    public function __set(string $name, mixed $value): void
    {
        unset($name, $value);
    }
}
