<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

trait PublicPipeTrait
{
    /** @param \Closure(string): string $next */
    public function handle(string $request, \Closure $next): string
    {
        return $next($request);
    }
}
