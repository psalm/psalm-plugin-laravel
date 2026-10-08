<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

use IndirectMethodReferencesFixture\Dependencies\InvokeParamDependency;

final class PlainInvokable
{
    public function __construct(private readonly InvokableService $service) {}

    public function __invoke(InvokeParamDependency $dependency): string
    {
        return $this->service->run() . \get_debug_type($dependency);
    }
}
