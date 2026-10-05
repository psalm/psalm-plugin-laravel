<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Application\Fixtures;

// Stands in for a load-time deprecation, which Psalm's error handler turns into an exception (#1652).
throw new \RuntimeException('raised while loading');

// Declared at run time, so the throw above leaves it undeclared.
if (true) {
    final class ThrowsOnLoadService {}
}
