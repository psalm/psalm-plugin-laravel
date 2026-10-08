<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Conventions;

final class UntypedNextMiddleware
{
    public function handle(string $request, $next): void
    {
        \assert($request !== '' && \is_callable($next));
    }
}
