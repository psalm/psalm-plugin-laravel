<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Psalm\Type\Union;

/**
 * Represents the resolved type and taint information for a single validated field.
 *
 * @psalm-immutable
 */
final class ResolvedRule
{
    /**
     * @param Union $type              The inferred Psalm type for the validated value
     * @param int   $removedTaints     Bitmask of TaintKind flags that this rule escapes
     * @param bool  $nullable          Whether the 'nullable' modifier was present
     * @param bool  $sometimes         Whether the field may be absent from validated output
     * @param bool  $required          Whether 'required' (or similar presence rule) was present
     */
    public function __construct(
        public readonly Union $type,
        public readonly int $removedTaints,
        public readonly bool $nullable,
        public readonly bool $sometimes,
        public readonly bool $required = false,
    ) {}
}
