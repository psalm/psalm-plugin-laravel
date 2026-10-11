<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Node\Scalar\String_;

/**
 * Reads three facts about class components off a compiled shadow's tokens:
 *
 *  - which classes a `<x-…>` tag (or `@component(Foo::class)`) renders: Laravel compiles both to
 *    `$component = Name::resolve(…)` (`CompilesComponents::compileClassComponentOpening()`), with
 *    the class as a bare qualified name, a quoted literal, or `Name::class`;
 *  - which named slots a caller passes: `<x-slot:name>` and `@slot('name')` compile to
 *    `$__env->slot('name', …)`;
 *  - which views a template renders by NAME (`@include`, `@includeIf`/`When`/`Unless`/`First`,
 *    `@each`, `@extends`, `@component('view')`): all compile to a `$__env->` call carrying the
 *    name as a literal. Such a render never runs `Component::data()`.
 *
 * The first two are lower bounds. `<x-dynamic-component>`, `Blade::renderComponent()`, package
 * views under a vendor hint root, and a slot whose name is an expression are invisible here. The
 * third over-approximates on purpose: every literal anywhere in such a call's arguments counts.
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

    /** `Factory` methods the by-name render directives compile to. */
    private const RENDER_BY_NAME = [
        'make' => true, 'first' => true, 'renderwhen' => true, 'renderunless' => true,
        'rendereach' => true, 'startcomponent' => true, 'startcomponentfirst' => true,
    ];

    /**
     * @param array<array-key, array{0: int, 1: string, 2: int}|string> $tokens {@see \token_get_all()} output
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} lowercased, `\`-stripped class
     *         names a tag renders, literal named-slot names, and view names rendered by name
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
        $views = [];
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

            if ($token[1] !== '$__env'
                || !self::is($significant[$i + 1] ?? null, \T_OBJECT_OPERATOR)
                || !self::is($significant[$i + 2] ?? null, \T_STRING)
                || ($significant[$i + 3] ?? null) !== '('
            ) {
                continue;
            }

            $method = \is_array($significant[$i + 2]) ? \strtolower($significant[$i + 2][1]) : '';

            if ($method === 'slot') {
                $name = self::literal($significant[$i + 4] ?? null);

                if ($name !== null) {
                    $slots[$name] = true;
                }
            } elseif (isset(self::RENDER_BY_NAME[$method])) {
                foreach (self::literalsInArguments($significant, $i + 3) as $name) {
                    $views[$name] = true;
                }
            }
        }

        return [
            \array_map(\strval(...), \array_keys($classes)),
            \array_map(\strval(...), \array_keys($slots)),
            \array_map(\strval(...), \array_keys($views)),
        ];
    }

    /**
     * Every string literal inside the balanced argument list opening at `$open`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<string>
     */
    private static function literalsInArguments(array $tokens, int $open): array
    {
        $literals = [];
        $depth = 0;
        $count = \count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } else {
                $literal = self::literal($token);

                if ($literal !== null) {
                    $literals[] = $literal;
                }
            }
        }

        return $literals;
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
            $name = self::literal($token);
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

    /**
     * A plain string literal's value, in either quote style: the author's own quoting survives into
     * compiled `@include("…")` / `@slot("…")` arguments.
     *
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private static function literal(array|string|null $token): ?string
    {
        if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        try {
            // The same unescaper ClassLiteralCollector uses: correct for both quote styles.
            /** @psalm-suppress InternalMethod */
            return String_::parse($token[1]);
        } catch (\Throwable) {
            return null;
        }
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
}
