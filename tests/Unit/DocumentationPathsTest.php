<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Agent-facing docs route by file path; a dangling path silently sends every
 * future session exploring from scratch. This lint keeps the routing honest.
 */
#[CoversNothing]
final class DocumentationPathsTest extends TestCase
{
    private const DOCS = [
        'CLAUDE.md',
        'docs/contributing/code-patterns.md',
        'docs/contributing/type-coverage.md',
    ];

    #[Test]
    public function every_backtick_path_reference_exists(): void
    {
        $root = \dirname(__DIR__, 2);
        $checked = 0;

        foreach (self::DOCS as $doc) {
            $content = \file_get_contents($root . '/' . $doc);
            $this->assertIsString($content, "unreadable: {$doc}");

            // Match path-shaped substrings directly instead of pairing backticks: code
            // fences (```) contain an odd backtick run that scrambles pair alignment and
            // silently drops tokens, and fence bodies carry real paths worth checking.
            \preg_match_all('~(?<![A-Za-z0-9_-])(?:src|docs|tests|stubs|bin|vendor|\.github)/[A-Za-z0-9_\-./*<>]*~', $content, $matches);

            foreach ($matches[0] as $token) {
                $path = $this->normalize($token);
                if ($path === null) {
                    continue;
                }

                ++$checked;
                $this->assertFileExists("{$root}/{$path}", "{$doc} references `{$token}` but {$path} does not exist");
            }
        }

        // A regex or doc-shape drift that stops matching paths would otherwise pass vacuously.
        $this->assertGreaterThan(15, $checked, "extractor matched only {$checked} paths; the doc shape or regex drifted");
    }

    /**
     * Reduce a matched token to a checkable repo-relative path, or null for
     * placeholders (`stubs/<version>/`) and globs (`tests/Type/psalm-*.xml`).
     */
    private function normalize(string $token): ?string
    {
        if (\preg_match('/[<>*]/', $token) === 1) {
            return null;
        }

        // Trailing sentence punctuation and dir slashes are not part of the path.
        $path = \rtrim($token, './');

        return $path === '' ? null : $path;
    }
}
