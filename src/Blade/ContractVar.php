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
    public function __construct(
        public readonly string $name,
        public readonly string $typeString,
        public readonly int $declarationLine,
        public readonly bool $optional,
    ) {}
}
