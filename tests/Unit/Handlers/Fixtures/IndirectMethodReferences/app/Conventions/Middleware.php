<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

final class AddHeaderMiddleware
{
    public function __construct(
        private readonly string $handlePrefix = 'handle',
        private readonly string $terminateSuffix = 'terminate',
    ) {}

    /** @param \Closure(string): string $next */
    public function handle(string $request, \Closure $next): string
    {
        return $next($this->handlePrefix . $request);
    }

    public function terminate(string $request, string $response): void
    {
        \assert($request . $response . $this->terminateSuffix !== '');
    }
}
