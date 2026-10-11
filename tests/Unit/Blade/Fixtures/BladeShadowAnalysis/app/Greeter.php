<?php

declare(strict_types=1);

namespace BladeShadowFixture;

// One real project file so <projectFiles> is not empty and the run takes Psalm's full branch.
final class Greeter
{
    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }
}
