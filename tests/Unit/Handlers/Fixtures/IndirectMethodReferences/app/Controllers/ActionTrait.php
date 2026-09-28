<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Controllers;

use IndirectMethodReferencesFixture\Dependencies\TraitActionDependency;

trait ActionTrait
{
    public function traitAction(TraitActionDependency $dependency): void {}
}
