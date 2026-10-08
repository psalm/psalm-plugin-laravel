<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

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
