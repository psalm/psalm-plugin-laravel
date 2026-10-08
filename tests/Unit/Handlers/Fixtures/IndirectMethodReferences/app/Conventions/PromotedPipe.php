<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use IndirectMethodReferencesFixture\Dependencies\PromotedPipeDependency;

final class PromotedPipe extends PromotedPipeBase
{
    public function __construct(PromotedPipeDependency $dependency)
    {
        \assert(\is_object($dependency));
    }
}
