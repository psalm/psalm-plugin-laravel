<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

/** A plain invokable: only Laravel's router/container calls `__invoke()`, so every dependency below hangs on it. */
final class PlainInvokable
{
    public function __construct(private readonly InvokableService $service) {}

    public function __invoke(InvokeParamDependency $dependency): string
    {
        return $this->service->run() . \get_debug_type($dependency);
    }
}

final class InvokableService
{
    public function __construct(private readonly string $label = 'service') {}

    public function run(): string
    {
        return $this->format();
    }

    private function format(): string
    {
        return \strtoupper($this->label);
    }
}

final class InvokeParamDependency
{
    public function __construct()
    {
        \assert(\class_exists(self::class));
    }
}
