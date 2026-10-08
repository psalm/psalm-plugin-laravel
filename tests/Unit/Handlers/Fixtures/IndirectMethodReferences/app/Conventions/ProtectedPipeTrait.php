<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

trait ProtectedPipeTrait
{
    /** @param \Closure(string): string $next */
    protected function handle(string $request, \Closure $next): string
    {
        return $next($request);
    }
}
