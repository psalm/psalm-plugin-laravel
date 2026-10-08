<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use IndirectMethodReferencesFixture\Dependencies\DemotedPipeDependency;

final class DemotedPipe extends DemotedPipeBase
{
    public function __construct(DemotedPipeDependency $dependency)
    {
        \assert(\is_object($dependency));
    }
}
