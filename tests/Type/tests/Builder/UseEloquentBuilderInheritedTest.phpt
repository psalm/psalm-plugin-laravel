--FILE--
<?php declare(strict_types=1);

use App\Models\AttributeInheritedBuilderChild;

/**
 * Issue #2109 — Laravel resolves #[UseEloquentBuilder] through the class hierarchy, so a model whose
 * abstract parent carries the attribute gets the custom builder at runtime.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2109
 */

/** The child declares no attribute of its own; the builder comes from the abstract parent. */
function test_child_inherits_builder_from_abstract_parent_attribute(): void
{
    $_query = AttributeInheritedBuilderChild::query();
    /** @psalm-check-type-exact $_query = \App\Builders\AttributeInheritedBuilder<AttributeInheritedBuilderChild> */

    $_chained = AttributeInheritedBuilderChild::query()->whereArchived();
    /** @psalm-check-type-exact $_chained = \App\Builders\AttributeInheritedBuilder<AttributeInheritedBuilderChild> */
}
?>
--EXPECTF--
