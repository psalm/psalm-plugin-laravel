<?php

declare(strict_types=1);

namespace BladeDisabledFixture;

// One real, issue-free project file: <projectFiles> is not empty, and any non-zero exit is the plugin's.
/** @psalm-immutable */
final class Greeter
{
    /** @psalm-pure */
    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }
}
