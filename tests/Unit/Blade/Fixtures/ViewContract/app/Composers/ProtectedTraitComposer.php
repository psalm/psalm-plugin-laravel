<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

// The `as protected` adaptation hides the trait's public method from the container's call.
final class ProtectedTraitComposer
{
    use ComposesTitle {
        compose as protected;
    }

    /** @param list<mixed> $arguments */
    public function __call(string $name, array $arguments): void
    {
        $arguments[0]->with('count', 1);
    }
}
