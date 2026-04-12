<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use Psalm\Type\Union;

/**
 * Represents the resolved type and taint information for a single validated field.
 *
 * @psalm-immutable
 */
final readonly class ResolvedRule
{
    /**
     * @param Union $type The inferred Psalm type for the validated value
     * @param list<string> $removedTaints TaintKind strings (e.g. TaintKind::INPUT_HTML) that this rule escapes
     * @param bool $nullable Whether the 'nullable' modifier was present
     * @param bool $sometimes Whether the field may be absent from validated output
     * @param bool $required Whether 'required' (or similar presence rule) was present
     */
    public function __construct(
        public Union $type,
        public array $removedTaints,
        public bool $nullable,
        public bool $sometimes,
        public bool $required = false,
    ) {}
}
