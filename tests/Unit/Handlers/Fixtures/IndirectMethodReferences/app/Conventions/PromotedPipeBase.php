<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

abstract class PromotedPipeBase
{
    use ProtectedPipeTrait {
        handle as public;
    }
}
