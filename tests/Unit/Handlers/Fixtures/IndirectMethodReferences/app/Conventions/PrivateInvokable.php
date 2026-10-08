<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use IndirectMethodReferencesFixture\Dependencies\PrivateInvokeDependency;

final class PrivateInvokable
{
    private function __invoke(PrivateInvokeDependency $dependency): void
    {
        \assert(\is_object($dependency));
    }
}
