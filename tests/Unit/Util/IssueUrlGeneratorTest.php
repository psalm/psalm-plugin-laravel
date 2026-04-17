<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\IssueUrlGenerator;
use ReflectionMethod;

use function parse_str;
use function parse_url;
use function strlen;

use const PHP_URL_QUERY;

#[CoversClass(IssueUrlGenerator::class)]
final class IssueUrlGeneratorTest extends TestCase
{
    #[Test]
    public function url_points_to_new_issue_form_with_bug_report_template(): void
    {
        $url = IssueUrlGenerator::generate(new \RuntimeException('boom'));

        self::assertStringStartsWith(
            'https://github.com/psalm/psalm-plugin-laravel/issues/new?template=bug_report.md',
            $url,
        );
    }

    #[Test]
    public function title_prefixes_plugin_initialization_error(): void
    {
        $title = self::titleFrom(IssueUrlGenerator::generate(new \RuntimeException('boom')));

        self::assertSame('Plugin initialization error: boom', $title);
    }

    /**
     * Reproduces the real-world title reported by a user against an absolute Laravel
     * path (see issue #745). The raw PHP-Error-wrapped-by-Psalm message contains an
     * absolute path and a trailing CLI args blob — both must be stripped.
     */
    #[Test]
    public function title_matches_hand_edited_version_from_reported_issue(): void
    {
        $rawMessage = 'PHP Error: Class "Introspect" not found in '
            . '/Users/matthewdally/docker/business-directory/vendor/laravel/framework/src/Illuminate/Foundation/AliasLoader.php:79'
            . ' for command with CLI args "./vendor/bin/psalm --no-cache --config=psalm.xml"';

        $title = self::titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage)));

        self::assertSame(
            'Plugin initialization error: Class "Introspect" not found',
            $title,
        );
    }

    #[Test]
    public function title_strips_windows_paths_containing_spaces(): void
    {
        $rawMessage = 'Class "Foo" not found in C:\\Users\\John Doe\\project\\src\\File.php:12';

        $title = self::titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage)));

        self::assertSame('Plugin initialization error: Class "Foo" not found', $title);
    }

    /** @return iterable<string, array{string, string}> */
    public static function leadingPhpPrefixes(): iterable
    {
        yield 'PHP Error' => ['PHP Error: Something broke', 'Something broke'];
        yield 'PHP Fatal error' => ['PHP Fatal error: Something broke', 'Something broke'];
        yield 'PHP Warning' => ['PHP Warning: Something broke', 'Something broke'];
        yield 'PHP Notice' => ['PHP Notice: Something broke', 'Something broke'];
        yield 'case insensitive' => ['PHP fatal ERROR: Something broke', 'Something broke'];
    }

    #[Test]
    #[DataProvider('leadingPhpPrefixes')]
    public function title_strips_leading_php_level_prefix(string $rawMessage, string $expectedTail): void
    {
        $title = self::titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage)));

        self::assertSame('Plugin initialization error: ' . $expectedTail, $title);
    }

    #[Test]
    public function title_does_not_strip_non_php_colon_prefixes(): void
    {
        $title = self::titleFrom(IssueUrlGenerator::generate(new \RuntimeException('Class not found: Foo')));

        self::assertSame('Plugin initialization error: Class not found: Foo', $title);
    }

    /**
     * Guard against long titles sneaking back in via future regressions —
     * the sanitised title for the reported example must stay well under the
     * original raw message length so GitHub's issue form does not truncate it.
     */
    #[Test]
    public function sanitised_title_is_shorter_than_raw_message(): void
    {
        $rawMessage = 'PHP Error: Class "Introspect" not found in '
            . '/Users/matthewdally/docker/business-directory/vendor/laravel/framework/src/Illuminate/Foundation/AliasLoader.php:79'
            . ' for command with CLI args "./vendor/bin/psalm --no-cache --config=psalm.xml"';

        $title = self::titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage)));
        $expectedTitle = 'Plugin initialization error: Class "Introspect" not found';

        self::assertSame($expectedTitle, $title);
        self::assertLessThan(strlen($rawMessage), strlen($expectedTitle));
    }

    #[Test]
    public function body_includes_fenced_trace_block(): void
    {
        $body = self::bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom')));

        self::assertStringContainsString("```\n", $body);
        self::assertStringContainsString('RuntimeException', $body);
        self::assertStringContainsString('boom', $body);
    }

    #[Test]
    public function body_lists_plugin_version_from_installed_versions(): void
    {
        $body = self::bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom')));

        // psalm/plugin-laravel is this very package — always resolvable during test runs.
        self::assertStringContainsString('**Versions:**', $body);
        self::assertStringContainsString('- psalm/plugin-laravel:', $body);
    }

    /**
     * The trace-specific tests exercise the private `sanitizeTrace()` directly so the
     * synthetic absolute paths we want to observe are not entangled with PHPUnit's own
     * stack-frame-argument truncation, which otherwise rewrites them as `/foo/bar/ap...`
     * before the sanitizer can see a `vendor/` or `src/` segment.
     */
    private static function invokeSanitizeTrace(string $input): string
    {
        $method = new ReflectionMethod(IssueUrlGenerator::class, 'sanitizeTrace');

        return (string) $method->invoke(null, $input);
    }

    #[Test]
    public function trace_collapses_absolute_vendor_path(): void
    {
        $input = '#0 /home/bob/app/vendor/vimeo/psalm/src/Foo.php(99): Bar->baz()';

        $output = self::invokeSanitizeTrace($input);

        self::assertSame(
            '#0 vendor/vimeo/psalm/src/Foo.php(99): Bar->baz()',
            $output,
        );
    }

    #[Test]
    public function trace_collapses_absolute_src_path(): void
    {
        $input = '#0 /home/bob/psalm-plugin-laravel/src/Plugin.php(697): X->y()';

        $output = self::invokeSanitizeTrace($input);

        self::assertSame('#0 src/Plugin.php(697): X->y()', $output);
    }

    /**
     * Regression: on checkouts like /Users/alice/src/project/vendor/… a naive
     * `(vendor/|src/)` alternation would stop at the earlier /src/ in the home
     * dir and leak "project/vendor/…". The vendor pass must consume the whole
     * prefix.
     */
    #[Test]
    public function trace_does_not_leak_src_inside_absolute_prefix_when_vendor_is_present(): void
    {
        $input = '#0 /Users/alice/src/project/vendor/laravel/framework/src/Illuminate/F.php(9): A->b()';

        $output = self::invokeSanitizeTrace($input);

        self::assertSame(
            '#0 vendor/laravel/framework/src/Illuminate/F.php(9): A->b()',
            $output,
        );
    }

    /**
     * Regression: vendor paths contain an inner "src/" directory
     * (vendor/laravel/framework/src/…). A too-greedy regex would collapse that
     * to "vendorsrc/…" and lose the framework path.
     */
    #[Test]
    public function trace_preserves_inner_src_inside_vendor_path(): void
    {
        $input = '#0 /home/u/app/vendor/laravel/framework/src/Illuminate/Foundation/AliasLoader.php(79): X->y()';

        $output = self::invokeSanitizeTrace($input);

        self::assertSame(
            '#0 vendor/laravel/framework/src/Illuminate/Foundation/AliasLoader.php(79): X->y()',
            $output,
        );
        self::assertStringNotContainsString('vendorsrc', $output);
    }

    #[Test]
    public function trace_sanitises_quoted_path_arguments_in_stack_frame(): void
    {
        $input = "#0 /a/vendor/b.php(9): X->y('/dev/vendor/z.php', 79)";

        $output = self::invokeSanitizeTrace($input);

        self::assertSame(
            "#0 vendor/b.php(9): X->y('vendor/z.php', 79)",
            $output,
        );
    }

    #[Test]
    public function trace_collapses_windows_backslash_path(): void
    {
        $input = '#0 C:\\Users\\carol\\src\\app\\vendor\\laravel\\framework\\src\\Foo.php(1): X->y()';

        $output = self::invokeSanitizeTrace($input);

        self::assertSame(
            '#0 vendor\\laravel\\framework\\src\\Foo.php(1): X->y()',
            $output,
        );
    }

    #[Test]
    public function trace_only_collapses_src_when_no_vendor_segment_exists(): void
    {
        // When no vendor/ segment exists, the src pass handles the rewrite.
        $input = '#0 /home/u/project/src/Plugin.php(42): X->y()';

        $output = self::invokeSanitizeTrace($input);

        self::assertSame('#0 src/Plugin.php(42): X->y()', $output);
    }

    #[Test]
    public function trace_leaves_already_relative_paths_untouched(): void
    {
        $input = '#0 vendor/already/relative.php(1): X->y()';

        $output = self::invokeSanitizeTrace($input);

        self::assertSame($input, $output);
    }

    private static function titleFrom(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);

        $params = [];
        parse_str($query, $params);

        self::assertArrayHasKey('title', $params);
        self::assertIsString($params['title']);

        return $params['title'];
    }

    private static function bodyFrom(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);

        $params = [];
        parse_str($query, $params);

        self::assertArrayHasKey('body', $params);
        self::assertIsString($params['body']);

        return $params['body'];
    }
}
