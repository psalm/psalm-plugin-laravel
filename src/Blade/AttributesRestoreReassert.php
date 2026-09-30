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
 * A regex match alone is not proof the matched text is COMPILER output (external review finding 1):
 * the identical text can appear inside an author's own PHP comment, where the injected suffix's
 * closing `*` `/` would end that comment (and `?>` would end php mode) early. `apply()` therefore
 * only accepts a match whose start offset is a real {@see \T_OPEN_TAG} per {@see \token_get_all()}
 * — the position PHP's own lexer treats as a genuine transition into PHP mode, which text sitting
 * inside a comment or string token can never be.
 *
 * A match also is not proof `$attributes` is genuinely non-null there (external review finding 2):
 * it proves only that LARAVEL'S OWN bookkeeping didn't null it. An author's own reassignment
 * between `@props` and the tag survives untouched by either compiled block (the save is
 * `isset($attributes)`-gated and runs before it). {@see self::templateAssignsAttributes()} scans
 * the raw template source for that and {@see ShadowCompiler} skips the whole pass when it finds one.
 *
 * @internal
 */
final class AttributesRestoreReassert
{
    /** The restore Laravel emits after a `<x-...>` tag, `CompilesComponents.php:99-102`. */
    private const RESTORE_PATTERN = '/<\?php if \(isset\(\$__attributesOriginal[0-9a-f]+\)\): \?>\R'
        . '<\?php \$attributes = \$__attributesOriginal[0-9a-f]+; \?>\R'
        . '<\?php unset\(\$__attributesOriginal[0-9a-f]+\); \?>\R'
        . '<\?php endif; \?>/';

    /** The ignored-parameter strip Laravel emits INSIDE a `<x-...>` tag, `ComponentTagCompiler.php:262-264`. */
    private const STRIP_PATTERN = '/<\?php if \(isset\(\$attributes\) && \$attributes instanceof Illuminate\\\\View\\\\ComponentAttributeBag\): \?>\R'
        . '<\?php \$attributes = \$attributes->except\(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*::ignoredParameterNames\(\)\); \?>\R'
        . '<\?php endif; \?>/';

    private const REASSERT_SUFFIX = ' /** @var \Illuminate\View\ComponentAttributeBag $attributes */ ?>';

    /**
     * `$attributes` followed by a bare `=`: a plain assignment can hand it ANY value, including a
     * null one, which is exactly what the restore/strip blocks cannot see. The negative lookahead
     * excludes `==`/`===`; every compound operator (`.=`, `+=`, `??=`, ...) already fails to match
     * because its extra character sits directly before the `=`, past the `\s*`.
     */
    private const ASSIGNMENT_PATTERN = '/\$attributes\s*=(?!=)/';

    private const UNSET_PATTERN = '/\bunset\s*\([^)]*\$attributes\b/';

    /** `[$a, $attributes] = ...` / `[$attributes] = ...` destructuring. */
    private const DESTRUCTURE_PATTERN = '/\[[^\[\]]*\$attributes\b[^\[\]]*\]\s*=(?!=)/';

    /** Only called for a template {@see PreludeBuilder::isComponentView()} already recognises. */
    public static function apply(string $compiled): string
    {
        $compiled = self::applyPattern(self::RESTORE_PATTERN, $compiled);

        return self::applyPattern(self::STRIP_PATTERN, $compiled);
    }

    /**
     * Whether the template's OWN source assigns to, or unsets, `$attributes` anywhere. Scanned over
     * {@see MarkerPrePass::blankInertText()}'s output (the same "is this text ever executed"
     * classification {@see PreludeBuilder::componentTypesFor()} already relies on), so a mention
     * inside a Blade comment or `@verbatim` body — dead text, never executed — cannot trip it; a
     * mention inside `@php`/raw PHP is live and does.
     */
    public static function templateAssignsAttributes(string $source): bool
    {
        $executable = MarkerPrePass::blankInertText($source);

        return \preg_match(self::ASSIGNMENT_PATTERN, $executable) === 1
            || \preg_match(self::UNSET_PATTERN, $executable) === 1
            || \preg_match(self::DESTRUCTURE_PATTERN, $executable) === 1;
    }

    /** @param non-empty-string $pattern always one of this class's own regex constants */
    private static function applyPattern(string $pattern, string $compiled): string
    {
        if (\preg_match_all($pattern, $compiled, $matches, \PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
            return $compiled;
        }

        $openTagOffsets = self::openTagOffsets($compiled);

        /** @var array{0: string, 1: int} $match */
        foreach (\array_reverse($matches[0]) as $match) {
            [$whole, $offset] = $match;

            if (!isset($openTagOffsets[$offset])) {
                continue;
            }

            $replacement = \substr($whole, 0, -\strlen(' ?>')) . self::REASSERT_SUFFIX;
            $compiled = \substr_replace($compiled, $replacement, $offset, \strlen($whole));
        }

        return $compiled;
    }

    /**
     * Byte offsets where {@see \token_get_all()} found a genuine `<?php`/`<?=` transition into PHP
     * mode — never a byte range PHP's own lexer folded into a comment or string token, which is
     * exactly the distinction `apply()` needs to leave an author's own comment alone (finding 1).
     *
     * @return array<int, true>
     */
    private static function openTagOffsets(string $compiled): array
    {
        $offsets = [];
        $offset = 0;

        foreach (\token_get_all($compiled) as $token) {
            if (\is_array($token) && ($token[0] === \T_OPEN_TAG || $token[0] === \T_OPEN_TAG_WITH_ECHO)) {
                $offsets[$offset] = true;
            }

            $offset += \strlen(\is_array($token) ? $token[1] : $token);
        }

        return $offsets;
    }
}
