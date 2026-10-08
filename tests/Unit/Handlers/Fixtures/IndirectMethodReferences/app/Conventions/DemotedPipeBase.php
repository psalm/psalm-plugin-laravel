<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

abstract class DemotedPipeBase
{
    use PublicPipeTrait {
        handle as protected;
    }
}
