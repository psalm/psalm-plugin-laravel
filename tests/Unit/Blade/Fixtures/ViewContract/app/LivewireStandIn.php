<?php

declare(strict_types=1);

namespace Livewire;

/**
 * Stands in for `livewire/livewire`, which is deliberately not a dependency: the plugin reads a
 * component's class ancestry and declared properties, never the package. The two protected
 * properties mirror Livewire 3's own `Component`, which declares no public one.
 */
abstract class Component
{
    protected $__id;

    protected $__name;
}
