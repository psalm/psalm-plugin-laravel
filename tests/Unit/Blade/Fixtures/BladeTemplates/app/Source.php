<?php

declare(strict_types=1);

namespace Fx;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

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

    /** What `$paginator->links()` and spatie/laravel-html `open()` document: the interface, never a concrete class. */
    public static function htmlable(): Htmlable
    {
        throw new \RuntimeException('never called, type-only fixture');
    }

    /** @return array<string, Htmlable> */
    public static function htmlables(): array
    {
        throw new \RuntimeException('never called, type-only fixture');
    }

    public static function htmlableOrString(): Htmlable|string
    {
        throw new \RuntimeException('never called, type-only fixture');
    }

    public static function view(): View
    {
        throw new \RuntimeException('never called, type-only fixture');
    }

    public static function noToStringOrString(): NoToStringHtmlable|string
    {
        throw new \RuntimeException('never called, type-only fixture');
    }

    public function count(): int
    {
        return 3;
    }
}
