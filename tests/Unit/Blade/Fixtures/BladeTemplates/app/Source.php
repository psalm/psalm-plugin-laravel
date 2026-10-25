<?php

declare(strict_types=1);

namespace Fx;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\View\Factory;

/**
 * Producers with honest types for the templates under resources/views: unions and falsable values
 * for the echo-position gate, a Stringable, nullable receivers, and loop inputs.
 */
final class Source
{
    /** @return array<array-key, mixed> */
    public function arr(): array
    {
        return [];
    }

    public static function opt(string $key): string|array|null
    {
        return $key;
    }

    public function maybeFalse(): string|false
    {
        return false;
    }

    public static function take(string $value): string
    {
        return $value;
    }

    public function str(): Str1
    {
        return new Str1();
    }

    public function maybe(): ?string
    {
        return null;
    }

    public function maybeUser(): ?User
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function items(): array
    {
        return ['a', 'b'];
    }

    public function paginator(): Paginator
    {
        throw new \RuntimeException('never called, type-only fixture');
    }

    public function count(): int
    {
        return 3;
    }

    // The real getLastLoop() returns `(object) $last`, which IS a \stdClass instance, not just something
    // shaped like one. A userland caller relying on that class identity (e.g. a ?\stdClass-typed
    // wrapper) must stay clean.
    public function loopAsStdClass(Factory $factory): ?\stdClass
    {
        return $factory->getLastLoop();
    }
}
