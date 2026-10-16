<?php

declare(strict_types=1);

namespace Livewire;

/**
 * Stand-in for `livewire/livewire`'s base class: only what the seed reads. The real class also
 * declares `__call()`, which silences an undefined `$this->method()`; left out here so the fixture
 * can prove `$this` resolves against the component.
 */
abstract class Component
{
    protected ?string $__id = null;

    /** Livewire hands the view only the properties a subclass declares. */
    public bool $owned = false;

    /** Computed properties and other runtime reads. */
    public function __get(string $property): mixed
    {
        return null;
    }
}
