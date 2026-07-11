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
     * @param Union $type              The inferred Psalm type for the validated value
     * @param int   $removedTaints     Bitmask of TaintKind flags that this rule escapes
     * @param bool  $nullable          Whether the 'nullable' modifier was present
     * @param bool  $sometimes         Whether the field may be absent from validated output
     * @param bool  $required          Whether 'required' (or similar presence rule) was present
     * @param bool  $hasIntegerRule    Whether an explicit 'integer' rule was present
     * @param bool  $excluded          Whether an exclude/exclude_if/exclude_unless/exclude_with/exclude_without rule was present
     * @param bool  $hasAcceptedRule   Whether an explicit unconditional 'accepted' rule was present (not accepted_if)
     * @param bool  $hasDeclinedRule   Whether an explicit unconditional 'declined' rule was present (not declined_if)
     */
    public function __construct(
        public Union $type,
        public int $removedTaints,
        public bool $nullable,
        public bool $sometimes,
        public bool $required = false,
        public bool $hasIntegerRule = false,
        public bool $excluded = false,
        public bool $hasAcceptedRule = false,
        public bool $hasDeclinedRule = false,
    ) {}

    /**
     * Whether the rule unconditionally guarantees that the field will be
     * present in the validated output — `required` / `present` / `accepted`
     * / `declined` without a `sometimes` override, and without an
     * exclude/exclude_if/exclude_unless/exclude_with/exclude_without rule
     * (Laravel's Validator skips the field's other rules entirely once an
     * unconditional `exclude` fires, so no presence/type guarantee survives).
     *
     * Source of truth for the gate that drives both type narrowing and
     * taint escape on `$this->input('key')` / `$this->key` reads: the two
     * paths must agree, otherwise users can see narrowed types without
     * matching taint behaviour (or vice-versa).
     *
     * @psalm-mutation-free
     */
    public function guaranteesPresence(): bool
    {
        return $this->required && !$this->sometimes && !$this->excluded;
    }
}
