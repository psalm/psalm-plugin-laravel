<?php

declare(strict_types=1);

namespace Fx;

/**
 * No declared property: `ghost` exists only because `Fx\Plugins\ProvidedPropertyPlugin` says so (#2129).
 */
final class Provided
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
