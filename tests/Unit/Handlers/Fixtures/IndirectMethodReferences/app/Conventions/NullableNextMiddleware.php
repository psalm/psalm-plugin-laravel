<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

final class NullableNextMiddleware
{
    public function __construct(private readonly string $prefix = 'nullable') {}

    public function handle(string $request, ?\Closure $next = null): string
    {
        return $next === null ? $this->prefix . $request : $next($request);
    }
}
