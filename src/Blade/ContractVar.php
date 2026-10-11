<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * One declared template variable: either a `{{-- @var \FQCN $name --}}`
 * comment or a `@props([...])` entry (typeString `mixed`, `optional` set
 * from whether the entry carried a literal default).
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

    /** The type the shadow prelude declares for this name in the template body. */
    public function bodyType(): string
    {
        return $this->defaultType ?? $this->typeString;
    }
}
