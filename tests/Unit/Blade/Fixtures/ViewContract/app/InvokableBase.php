<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\View\Component;

abstract class InvokableBase extends Component
{
    /** Declared here, so `static` must resolve to the rendering component, not to this class. */
    public function fresh(): static
    {
        return $this;
    }
}
