<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Finds the layout a template `@extends` by repeating the steps of `BladeCompiler::compileString()`
 * that decide what is a directive: `@verbatim` and `@php` blocks are set aside, `{{-- --}}` comments
 * are removed (not masked: `@{{-- --}}@extends` is an escape), raw PHP is skipped, and directives
 * are matched with Blade's own pattern, each consuming its balanced arguments.
 *
 * Read off the source because a compiled `@extends` is indistinguishable from an `@include`.
 *
 * @internal
 *
 * @psalm-pure
 */
final class ExtendedLayout
{
    /** `BladeCompiler::compileStatements()` */
    private const DIRECTIVE = '/\B@(@?\w+(?:::\w+)?)([ \t]*)(\( ( [\S\s]*? ) \))?/x';

    /** `BladeCompiler::getRawPlaceholder()` */
    private const RAW_PLACEHOLDER = '@__raw_block_0__@';

    /**
     * Null when the template extends nothing; false when the layout cannot be proven a single
     * literal, non-namespaced view rendered with the template's scope alone (a dynamic or
     * interpolated name, `@extendsFirst`, a data argument, a second `@extends`). Slashes are
     * normalized to dots, as `Illuminate\View\ViewName::normalize()` does.
     *
     * @psalm-pure
     */
    public static function of(string $source): string|false|null
    {
        if (\stripos($source, '@extends') === false) {
            return null;
        }

        $value = self::withoutUncompiledText($source);

        if ($value === null) {
            return false;
        }

        $layout = null;

        foreach (\token_get_all($value) as $token) {
            if (!\is_array($token) || $token[0] !== \T_INLINE_HTML) {
                continue;
            }

            $statements = self::statements($token[1]);

            if ($statements === null) {
                return false;
            }

            foreach ($statements as [$name, $arguments]) {
                // Blade dispatches through method_exists(), which ignores case.
                $name = \strtolower($name);

                if ($name !== 'extends' && $name !== 'extendsfirst') {
                    continue;
                }

                if ($layout !== null || $name === 'extendsfirst' || $arguments === null) {
                    return false;
                }

                $layout = \preg_match('/^\s*(?|\'([^\'\\\\]+)\'|"([^"\\\\$]+)")\s*$/', $arguments, $literal) === 1
                    && !\str_contains($literal[1], '::')
                    ? \str_replace('/', '.', $literal[1])
                    : false;
            }
        }

        return $layout;
    }

    /**
     * `storeUncompiledBlocks()` and `compileComments()`; null when a pattern fails
     *
     * @psalm-pure
     */
    private static function withoutUncompiledText(string $value): ?string
    {
        if (\str_contains($value, '@verbatim')) {
            $value = \preg_replace('/(?<!@)@verbatim(\s*)(.*?)@endverbatim/s', '$1' . self::RAW_PLACEHOLDER, $value);
        }

        if ($value !== null && \str_contains($value, '@php')) {
            $value = \preg_replace('/(?<!@)@php(.*?)@endphp/s', self::RAW_PLACEHOLDER, $value);
        }

        return $value === null ? null : \preg_replace('/{{--(.*?)--}}/s', '', $value);
    }

    /**
     * The directives `compileStatements()` would compile in one inline-HTML chunk, as [name,
     * arguments without the outer parentheses]. Matches never overlap, and a match the paren
     * balancing swallows is dropped, so text inside a directive's arguments is never a directive.
     *
     * @return list<array{0: string, 1: string|null}>|null null when the pattern fails
     *
     * @psalm-pure
     */
    private static function statements(string $template): ?array
    {
        if (\preg_match_all(self::DIRECTIVE, $template, $matches) === false) {
            return null;
        }

        $statements = [];

        for ($i = 0; isset($matches[0][$i]); $i++) {
            $whole = $matches[0][$i];
            $name = $matches[1][$i];
            $arguments = $matches[3][$i];
            $inner = $matches[4][$i] === '' ? null : $matches[4][$i];

            while ($inner !== null && \str_ends_with($whole, ')') && !self::hasEvenNumberOfParentheses($whole)) {
                $parts = \explode($whole, $template, 2);

                if (\count($parts) < 2) {
                    break;
                }

                $rest = \explode(')', $parts[1], 2)[0];

                if (isset($matches[0][$i + 1]) && \str_contains($rest . ')', $matches[0][$i + 1])) {
                    unset($matches[0][$i + 1]);
                    $i++;
                }

                $whole .= $rest . ')';
                $arguments .= $rest . ')';
                $inner .= $rest;
            }

            $statements[] = [$name, $arguments === '' ? null : \substr($arguments, 1, -1)];
        }

        return $statements;
    }

    /**
     * `BladeCompiler::hasEvenNumberOfParentheses()`
     *
     * @psalm-pure
     */
    private static function hasEvenNumberOfParentheses(string $expression): bool
    {
        try {
            $tokens = \token_get_all('<?php ' . $expression);
        } catch (\ParseError) {
            return false;
        }

        if (\end($tokens) !== ')') {
            return false;
        }

        $opening = 0;
        $closing = 0;

        foreach ($tokens as $token) {
            if ($token === ')') {
                $closing++;
            } elseif ($token === '(') {
                $opening++;
            }
        }

        return $opening === $closing;
    }
}
