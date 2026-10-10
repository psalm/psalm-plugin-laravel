<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * One declared template variable: a `{{-- @var \FQCN $name --}}` comment, a raw
 * `@var` docblock in a `<?php` / `@php` block (`optional` set when its type includes
 * `null`), or a `@props([...])` entry (typeString `mixed`, `optional` set from whether
 * the entry carried a literal default).
 *
 * @psalm-immutable
 * @psalm-api
 */
final class ContractVar
{
    public function __construct(
        public readonly string $name,
        public readonly string $typeString,
        public readonly int $declarationLine,
        public readonly bool $optional,
        public readonly bool $raw = false,
    ) {}
}
