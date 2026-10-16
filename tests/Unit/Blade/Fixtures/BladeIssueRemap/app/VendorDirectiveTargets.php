<?php

declare(strict_types=1);

// The vendor classes CompiledResidualStubProvider's compiled directives name, cut down to the
// members those directives touch (#1908).

namespace Livewire;

class WireDirective
{
    public function value(): string
    {
        return '';
    }

    public function hasModifier(string $modifier): bool
    {
        return $modifier !== '';
    }
}

namespace Inertia\Ssr;

final class Response
{
    public function __construct(
        public string $head,
        public string $body,
    ) {}
}

interface Gateway
{
    /** @param array<string, mixed> $page */
    public function dispatch(array $page): ?Response;
}
