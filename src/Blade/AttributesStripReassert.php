<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Re-asserts `$attributes` as a non-null `ComponentAttributeBag` after the inner `except()` strip
 * `ComponentTagCompiler::componentString()` compiles into a `<x-...>` tag (#1543).
 *
 * The strip runs behind `isset($attributes) && $attributes instanceof ComponentAttributeBag`, which
 * the view's own bookkeeping has already made true, but Psalm keeps the reconciled `null` from the
 * never-taken negative arm and re-unions it into `$attributes`'s type at the block's `endif`, so a
 * read INSIDE the tag's own body reports PossiblyNullReference. The save/restore pair
 * `CompilesComponents::compileComponent()` wraps around the tag needs no re-assert since Psalm 7.7,
 * which no longer re-unions that null (a re-assert there is reported as UnnecessaryVarAnnotation).
 *
 * The block is matched by its full, distinctive statement sequence (not just its trailing `endif;`)
 * so an unrelated `endif;` elsewhere in the shadow can never match. The docblock lands on the SAME
 * line as the matched `endif;`, before its closing ` ?>`: no line is added and no new `<?php` open
 * tag is introduced, so neither {@see LineMapBuilder} nor {@see SuppressionInjector::findTargets()}
 * (which binds a template's `{{-- @psalm-suppress --}}` to the first open tag after it) sees
 * anything different.
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
 * between `@props` and the tag survives untouched by the compiled blocks (the save is
 * `isset($attributes)`-gated and runs before it). {@see self::templateAssignsAttributes()} scans
 * the raw template source for that and {@see ShadowCompiler} skips the whole pass when it finds one.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class AttributesStripReassert
{
    /** The ignored-parameter strip Laravel emits INSIDE a `<x-...>` tag, `ComponentTagCompiler.php:262-264`. */
    private const STRIP_PATTERN = '/<\?php if \(isset\(\$attributes\) && \$attributes instanceof Illuminate\\\\View\\\\ComponentAttributeBag\): \?>\R'
        . '<\?php \$attributes = \$attributes->except\(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*::ignoredParameterNames\(\)\); \?>\R'
        . '<\?php endif; \?>/';

    private const REASSERT_SUFFIX = ' /** @var \Illuminate\View\ComponentAttributeBag $attributes */ ?>';

    /**
     * `$attributes` followed by a bare `=`: a plain assignment can hand it ANY value, including a
     * null one, which is exactly what the compiled strip cannot see. The negative lookahead
     * excludes `==`/`===`; every compound operator (`.=`, `+=`, `??=`, ...) already fails to match
     * because its extra character sits directly before the `=`, past the `\s*`.
     */
    private const ASSIGNMENT_PATTERN = '/\$attributes\s*=(?!=)/';

    private const UNSET_PATTERN = '/\bunset\s*\([^)]*\$attributes\b/';

    /** `[$a, $attributes] = ...` / `[$attributes] = ...` destructuring. */
    private const DESTRUCTURE_PATTERN = '/\[[^\[\]]*\$attributes\b[^\[\]]*\]\s*=(?!=)/';

    /**
     * Only called for a template {@see PreludeBuilder::isComponentView()} already recognises.
     *
     * @psalm-pure
     */
    public static function apply(string $compiled): string
    {
        if (\preg_match_all(self::STRIP_PATTERN, $compiled, $matches, \PREG_OFFSET_CAPTURE) === false || $matches[0] === []) {
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
     * Whether the template's OWN source assigns to, or unsets, `$attributes` anywhere. Scanned over
     * {@see MarkerPrePass::blankInertText()}'s output (the same "is this text ever executed"
     * classification {@see PreludeBuilder::componentTypesFor()} already relies on), so a mention
     * inside a Blade comment or `@verbatim` body — dead text, never executed — cannot trip it; a
     * mention inside `@php`/raw PHP is live and does.
     *
     * @psalm-pure
     */
    public static function templateAssignsAttributes(string $source): bool
    {
        $executable = MarkerPrePass::blankInertText($source);

        return \preg_match(self::ASSIGNMENT_PATTERN, $executable) === 1
            || \preg_match(self::UNSET_PATTERN, $executable) === 1
            || \preg_match(self::DESTRUCTURE_PATTERN, $executable) === 1;
    }

    /**
     * Byte offsets where {@see \token_get_all()} found a genuine `<?php`/`<?=` transition into PHP
     * mode — never a byte range PHP's own lexer folded into a comment or string token, which is
     * exactly the distinction `apply()` needs to leave an author's own comment alone (finding 1).
     *
     * @return array<int, true>
     *
     * @psalm-pure
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
