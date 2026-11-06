<?php

declare(strict_types=1);

namespace Livewire;

use Illuminate\Support\Traits\Macroable;

/**
 * Stands in for `livewire/livewire`, which is deliberately not a dependency. Like the real class it
 * answers computed-property reads through `__get()` and macros through `__call()`; bootstrap/app.php
 * loads it to register one macro at boot.
 */
abstract class Component
{
    use Macroable {
        __call as macroCall;
    }

    /** @return mixed */
    public function __get(string $property)
    {
        throw new \LogicException($property);
    }

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return $this->macroCall($method, $parameters);
    }
}
