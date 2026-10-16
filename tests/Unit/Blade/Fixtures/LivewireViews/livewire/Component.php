<?php

declare(strict_types=1);

namespace Livewire;

/**
 * Stand-in for `livewire/livewire`'s base class: only what the seed reads, plus the magic Livewire 3
 * and 4 declare (computed properties through `__get()`, macros through `__call()`).
 */
abstract class Component
{
    protected ?string $__id = null;

    /** Livewire hands the view only the properties a subclass declares. */
    public bool $owned = false;

    public function __get(string $property): mixed
    {
        return null;
    }

    /** @param array<array-key, mixed> $params */
    public function __call(string $method, array $params): mixed
    {
        return null;
    }
}
