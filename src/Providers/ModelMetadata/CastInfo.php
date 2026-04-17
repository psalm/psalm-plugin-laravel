<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Psalm\Type\Union;

/**
 * Resolved description of a single `$casts` entry.
 *
 * Produced by the registry builder; consumers use {@see $psalmType} directly
 * and fall back to {@see $shape} / {@see $targetClass} when they need to
 * distinguish declaration styles (e.g. for error messages).
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class CastInfo
{
    /**
     * @param non-empty-string  $column       Column name (original case).
     * @param class-string|null $targetClass  Enum FQCN, collection item FQCN, or custom-cast FQCN.
     * @param Union             $psalmType    Pre-computed PHP-side type of the cast attribute.
     *                                        Column nullability is baked in when the matching
     *                                        schema column is nullable — consumers read the
     *                                        type directly without re-running `CastResolver`.
     * @param string|null       $parameter    Cast parameter (e.g. `'Y-m-d'` for `'datetime:Y-m-d'`).
     */
    public function __construct(
        public string $column,
        public CastShape $shape,
        public ?string $targetClass,
        public Union $psalmType,
        public ?string $parameter,
    ) {}
}
