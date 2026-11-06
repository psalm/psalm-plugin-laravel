<?php

declare(strict_types=1);

namespace Fx;

/**
 * A sealed interface declaring `__set`: the second member of an `Loose&SealedContract` intersection (#2129).
 */
interface SealedContract
{
    public function __set(string $name, mixed $value): void;
}
