<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation;

/**
 * A shadow issue's snippet with its selection as offsets into that snippet, so the string rules
 * in {@see TemplateSnippetMatcher} can run on plain text.
 *
 * Offsets are not validated here: each rule bounds-checks what it reads and declines on its own.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class ShadowSelection
{
    private function __construct(
        public readonly string $snippet,
        public readonly int $start,
        public readonly int $end,
    ) {}

    /**
     * Null on any Throwable: Psalm computes these lazily from file contents, and an unreadable
     * location is no evidence of anything, so every caller declines rather than crash or drop.
     *
     * @psalm-mutation-free
     */
    public static function of(CodeLocation $location): ?self
    {
        try {
            $snippet = $location->getSnippet();
            [$selectionStart, $selectionEnd] = $location->getSelectionBounds();
            [$snippetStart] = $location->getSnippetBounds();
        } catch (\Throwable) {
            return null;
        }

        return new self($snippet, $selectionStart - $snippetStart, $selectionEnd - $snippetStart);
    }
}
