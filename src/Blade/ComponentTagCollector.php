<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Scalar\String_;

/**
 * Reads two facts about class components off a compiled shadow's tokens:
 *
 *  - which classes a `<x-…>` tag (or `@component(Foo::class)`) renders: Laravel compiles both to
 *    `$component = Name::resolve(…)` (`CompilesComponents::compileClassComponentOpening()`), with
 *    the class as a bare qualified name, a quoted literal, or `Name::class`;
 *  - which named slots a caller passes: `<x-slot:name>` and `@slot('name')` compile to
 *    `$__env->slot('name', …)`.
 *
 * Both are lower bounds. `<x-dynamic-component>`, `Blade::renderComponent()`, package views under a
 * vendor hint root, and a slot whose name is an expression are invisible here.
 *
 * Anchored on `$component =` rather than on a bare `::resolve(`: that assignment is the compiler's
 * own prologue, so an author's unrelated static `resolve()` call is not read as a render.
 *
 * @internal
 */
final class ComponentTagCollector
{
    private const INSIGNIFICANT = [\T_WHITESPACE => true, \T_COMMENT => true, \T_DOC_COMMENT => true];

    private const CLASS_NAME_TOKENS = [\T_STRING => true, \T_NAME_QUALIFIED => true, \T_NAME_FULLY_QUALIFIED => true];

    /**
     * @param array<array-key, array{0: int, 1: string, 2: int}|string> $tokens {@see \token_get_all()} output
     *
     * @return array{0: list<string>, 1: list<string>} lowercased, `\`-stripped class names a tag
     *                                                 renders, and literal named-slot names
     */
    public static function collect(array $tokens): array
    {
        $significant = [];

        foreach ($tokens as $token) {
            if (!\is_array($token) || !isset(self::INSIGNIFICANT[$token[0]])) {
                $significant[] = $token;
            }
        }

        $classes = [];
        $slots = [];
        $count = \count($significant);

        for ($i = 0; $i < $count; $i++) {
            $token = $significant[$i];

            if (!\is_array($token) || $token[0] !== \T_VARIABLE) {
                continue;
            }

            if ($token[1] === '$component' && ($significant[$i + 1] ?? null) === '=') {
                $class = self::resolvedClass($significant, $i + 2);

                if ($class !== null) {
                    $classes[$class] = true;
                }

                continue;
            }

            if ($token[1] === '$__env' && self::isSlotCall($significant, $i + 1)) {
                $name = self::literal($significant[$i + 4] ?? null);

                if ($name !== null) {
                    $slots[$name] = true;
                }
            }
        }

        return [\array_map(\strval(...), \array_keys($classes)), \array_map(\strval(...), \array_keys($slots))];
    }

    /**
     * `Name::resolve(`, `'Name'::resolve(`, or `Name::class::resolve(`, starting at `$at`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function resolvedClass(array $tokens, int $at): ?string
    {
        $token = $tokens[$at] ?? null;

        if (!\is_array($token)) {
            return null;
        }

        if ($token[0] === \T_CONSTANT_ENCAPSED_STRING) {
            $name = self::classLiteral($token[1]);
        } elseif (isset(self::CLASS_NAME_TOKENS[$token[0]])) {
            $name = $token[1];

            if (self::is($tokens[$at + 1] ?? null, \T_DOUBLE_COLON) && self::is($tokens[$at + 2] ?? null, \T_CLASS)) {
                $at += 2;
            }
        } else {
            return null;
        }

        if ($name === null
            || !self::is($tokens[$at + 1] ?? null, \T_DOUBLE_COLON)
            || !self::is($tokens[$at + 2] ?? null, \T_STRING, 'resolve')
            || ($tokens[$at + 3] ?? null) !== '('
        ) {
            return null;
        }

        $name = \ltrim($name, '\\');

        return $name === '' ? null : \strtolower($name);
    }

    private static function classLiteral(string $tokenText): ?string
    {
        try {
            // The same unescaper ClassLiteralCollector uses: correct for both quote styles.
            /** @psalm-suppress InternalMethod */
            return String_::parse($tokenText);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `->slot(` followed by a string literal, starting right after `$__env`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @psalm-pure
     */
    private static function isSlotCall(array $tokens, int $at): bool
    {
        return self::is($tokens[$at] ?? null, \T_OBJECT_OPERATOR)
            && self::is($tokens[$at + 1] ?? null, \T_STRING, 'slot')
            && ($tokens[$at + 2] ?? null) === '(';
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     *
     * @psalm-pure
     */
    private static function is(array|string|null $token, int $id, ?string $lowercaseText = null): bool
    {
        return \is_array($token)
            && $token[0] === $id
            && ($lowercaseText === null || \strtolower($token[1]) === $lowercaseText);
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     *
     * @psalm-pure
     */
    private static function literal(array|string|null $token): ?string
    {
        if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        // Compiled output only ever quotes these with single quotes and no escapes; anything else is
        // not the compiler's shape.
        if (\preg_match("/^'([^'\\\\]*)'\$/", $token[1], $matched) !== 1) {
            return null;
        }

        return $matched[1];
    }
}
