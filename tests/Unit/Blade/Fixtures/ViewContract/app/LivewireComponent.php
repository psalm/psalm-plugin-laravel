<?php

declare(strict_types=1);

namespace Livewire;

// The minimal base a Livewire app extends. The real package is not a dev dependency, and the
// plugin recognizes a component by this class name alone.
abstract class Component
{
    // Declared by the base class, so Livewire hands it to no view.
    public string $id = "";
}
