<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

final class PlainHandleClass
{
    public function handle(): void
    {
        \assert(true);
    }
}
