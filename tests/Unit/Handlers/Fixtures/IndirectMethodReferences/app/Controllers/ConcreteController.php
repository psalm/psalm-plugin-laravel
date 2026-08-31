<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Controllers;

use IndirectMethodReferencesFixture\Dependencies\OwnerDependency;

final class ConcreteController extends \Illuminate\Routing\Controller
{
    public function __construct(OwnerDependency $dependency) {}

    public function show(): void {}
}
