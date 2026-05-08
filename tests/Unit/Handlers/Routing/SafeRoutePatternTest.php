<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Routing\SafeRoutePattern;

/**
 * Verifies the conservative whitelist of route-parameter regexes that the
 * plugin treats as "definitely defeats every taint sink". Two failure modes
 * matter:
 *
 *  - False positives (regex accepted but actually unsafe) → security regression,
 *    plugin would tell users a tainted value is clean.
 *  - False negatives (regex rejected but actually safe) → noisy warnings,
 *    cosmetic but annoying. We accept some false negatives by design.
 */
#[CoversClass(SafeRoutePattern::class)]
final class SafeRoutePatternTest extends TestCase
{
    /** @return \Iterator<string, array{string}> */
    public static function safePatternsProvider(): \Iterator
    {
        yield 'digits plus' => ['\d+'];
        yield 'digits star' => ['\d*'];
        yield 'numeric range' => ['[0-9]+'];
        yield 'lowercase alpha' => ['[a-z]+'];
        yield 'mixed alpha' => ['[a-zA-Z]+'];
        yield 'alphanumeric' => ['[a-zA-Z0-9]+'];
        yield 'koel slug example (issue #849)' => ['[A-Za-z0-9]+'];
        yield 'slug with dash' => ['[a-zA-Z0-9-]+'];
        yield 'slug with dash and underscore' => ['[a-zA-Z0-9_-]+'];
        yield 'word chars' => ['\w+'];
        yield 'hex' => ['[0-9a-f]+'];
        yield 'mixed hex' => ['[0-9a-fA-F]+'];
        yield 'standard uuid' => [
            '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        ];
        yield 'uuid case-insensitive' => [
            '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        ];
        yield 'ulid' => ['[0-9A-HJKMNP-TV-Z]{26}'];
        yield 'simple ulid' => ['[0-9A-Z]{26}'];
        yield 'whereIn-style alternation of safe literals' => ['draft|published|archived'];
        yield 'anchored digits' => ['^\d+$'];
    }

    /** @return \Iterator<string, array{string}> */
    public static function unsafePatternsProvider(): \Iterator
    {
        yield 'wildcard' => ['.+'];
        yield 'non-slash' => ['[^/]+'];
        yield 'with quote' => ["[a-zA-Z0-9']+"];
        yield 'with newline class' => ['[\s\S]+'];
        yield 'with shell metachar' => ['[A-Za-z0-9;]+'];
        yield 'with html bracket' => ['[A-Za-z0-9<>]+'];
        yield 'empty' => [''];
        yield 'permissive alternation' => ['draft|published|with;semicolon'];
        yield 'lookahead' => ['(?=\d)\d+'];
    }

    #[Test]
    #[DataProvider('safePatternsProvider')]
    public function safe_patterns_are_recognised(string $regex): void
    {
        $this->assertTrue(
            SafeRoutePattern::isSafe($regex),
            "Expected '{$regex}' to be classified as safe — false negative weakens taint suppression",
        );
    }

    #[Test]
    #[DataProvider('unsafePatternsProvider')]
    public function unsafe_patterns_are_rejected(string $regex): void
    {
        $this->assertFalse(
            SafeRoutePattern::isSafe($regex),
            "Expected '{$regex}' to be classified as UNSAFE — false positive would let tainted values through",
        );
    }

    #[Test]
    public function alternation_with_one_unsafe_alternative_is_unsafe(): void
    {
        // Even if 9 of 10 whereIn() values are safe, one with a quote breaks it.
        $this->assertFalse(SafeRoutePattern::isSafe("alpha|beta|gamma|with'quote"));
    }
}
