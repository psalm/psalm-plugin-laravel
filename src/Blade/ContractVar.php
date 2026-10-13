<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * One declared template variable: a raw `@var` docblock in a `<?php` / `@php` block
 * (`optional` only when the declaration ends in `= null`), or a `@props([...])` entry (typeString `mixed`, `optional` set from whether
 * the entry carried a literal default).
 *
 * @psalm-immutable
 * @psalm-api
 */
final class ContractVar
{
    /**
     * @param ?string $defaultType a `@props` entry's literal default, widened (`'lg'` => `string`).
     *                             It types the template body only, never the contract: a caller may
     *                             pass any value for the prop. Not persisted in the shadow manifest,
     *                             whose stored prelude already carries it.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $typeString,
        public readonly int $declarationLine,
        public readonly bool $optional,
        public readonly ?string $defaultType = null,
    ) {}
}
