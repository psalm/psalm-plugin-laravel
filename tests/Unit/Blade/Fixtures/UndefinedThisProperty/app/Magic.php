<?php

declare(strict_types=1);

namespace Fx;

/**
 * A magic-property receiver with no `@property` annotation for `missing`, so Psalm's `__get`
 * handling (armed by the fixture's `sealAllProperties="true"`) has nothing to resolve the fetch
 * against (#1545).
 */
final class Magic
{
    public function __get(string $name): mixed
    {
        return $name;
    }
}
