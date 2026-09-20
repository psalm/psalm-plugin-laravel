<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Whitespace-insensitive substring search, split out of {@see ShadowIssueRelocator} so the
 * matching rule is testable against plain strings, without a booted Psalm codebase to satisfy
 * `CodeLocation::getSelectedText()`.
 *
 * @internal
 */
final class TemplateSnippetMatcher
{
    /**
     * Whether $snippet appears in $source once both are collapsed to single-spaced text. A
     * multi-line snippet (a call spanning several lines) still matches this way; comparing
     * line-by-line instead would miss it and false-drop.
     */
    public static function occursIn(string $snippet, string $source): bool
    {
        return \str_contains(self::normalize($source), self::normalize($snippet));
    }

    private static function normalize(string $text): string
    {
        return \preg_replace('/\s+/', ' ', \trim($text)) ?? $text;
    }
}
