<?php

declare(strict_types=1);

namespace Fx;

/**
 * Opts out of sealing, so the `__set` check on this member of an intersection is silent while the
 * sealed interface member next to it is not (#2129).
 *
 * @psalm-no-seal-properties
 */
class Loose
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
