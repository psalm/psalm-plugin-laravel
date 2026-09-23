<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Re-asserts `$attributes` as a non-null `ComponentAttributeBag` after the two compiled
 * constructs that conditionally reassign it around a `<x-...>` tag (#1543).
 *
 * `CompilesComponents::compileComponent()` saves `$attributes` behind `isset($attributes)` before
 * the tag and restores it behind `isset($__attributesOriginal<hash>)` after; both `isset()` checks
 * are provably true by the time either block runs (the save only fires when `$attributes` already
 * existed, the restore only fires when the save did), but Psalm keeps the reconciled `null` from
 * the never-taken negative arm and re-unions it into `$attributes`'s type at each block's `endif`.
 * `ComponentTagCompiler::componentString()`'s inner `except()` strip has the identical shape one
 * block earlier, so a read INSIDE the tag's own body degrades before the restore ever runs (fixing
 * only the restore leaves that case red).
 *
 * Both blocks are matched by their full, distinctive statement sequence (not just their trailing
 * `endif;`) so an unrelated `endif;` elsewhere in the shadow can never match. The docblock lands on
 * the SAME line as the matched `endif;`, before its closing ` ?>`: no line is added and no new
 * `<?php` open tag is introduced, so neither {@see LineMapBuilder} nor
 * {@see SuppressionInjector::findTargets()} (which binds a template's `{{-- @psalm-suppress --}}`
 * to the first open tag after it) sees anything different.
 *
 * @internal
 */
final class AttributesRestoreReassert
{
    /** The restore Laravel emits after a `<x-...>` tag, `CompilesComponents.php:99-102`. */
    private const RESTORE_PATTERN = '/(<\?php if \(isset\(\$__attributesOriginal[0-9a-f]+\)\): \?>\R'
        . '<\?php \$attributes = \$__attributesOriginal[0-9a-f]+; \?>\R'
        . '<\?php unset\(\$__attributesOriginal[0-9a-f]+\); \?>\R'
        . '<\?php endif;) \?>/';

    /** The ignored-parameter strip Laravel emits INSIDE a `<x-...>` tag, `ComponentTagCompiler.php:262-264`. */
    private const STRIP_PATTERN = '/(<\?php if \(isset\(\$attributes\) && \$attributes instanceof Illuminate\\\\View\\\\ComponentAttributeBag\): \?>\R'
        . '<\?php \$attributes = \$attributes->except\(\\\\[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*::ignoredParameterNames\(\)\); \?>\R'
        . '<\?php endif;) \?>/';

    private const REASSERT_SUFFIX = ' /** @var \Illuminate\View\ComponentAttributeBag $attributes */ ?>';

    /** Only called for a template {@see PreludeBuilder::isComponentView()} already recognises. */
    public static function apply(string $compiled): string
    {
        $compiled = \preg_replace(self::RESTORE_PATTERN, '$1' . self::REASSERT_SUFFIX, $compiled) ?? $compiled;

        return \preg_replace(self::STRIP_PATTERN, '$1' . self::REASSERT_SUFFIX, $compiled) ?? $compiled;
    }
}
