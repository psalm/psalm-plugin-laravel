<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

/**
 * Intermediate node in the dot-notation validation rule tree.
 *
 * Used by {@see ValidatedTypeHandler} to convert flat dot-notation keys
 * (e.g., 'address.city') into a nested tree before building TKeyedArray shapes.
 *
 * - Leaf node: $rule is set, $children is empty
 * - Branch node: $children is non-empty, $rule may be null (intermediate) or set (has own rule)
 */
final class ValidationRuleNode
{
    public ?ResolvedRule $rule = null;

    /** @var array<string, ValidationRuleNode> */
    public array $children = [];
}
