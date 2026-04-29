<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\PluginConfig;
use Psalm\LaravelPlugin\Util\IssueUrlGenerator;

#[CoversClass(IssueUrlGenerator::class)]
final class IssueUrlGeneratorTest extends TestCase
{
    private ?string $originalCachePathEnv = null;

    protected function setUp(): void
    {
        $env = \getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        $this->originalCachePathEnv = $env !== false ? $env : null;

        // Start each test from a known-clean baseline; a developer-exported value
        // otherwise leaks into `defaultConfig()` and silently changes what the
        // body_includes_plugin_configuration_section_* tests actually assert.
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
    }

    protected function tearDown(): void
    {
        if ($this->originalCachePathEnv !== null) {
            \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=' . $this->originalCachePathEnv);
        } else {
            \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        }
    }

    #[Test]
    public function url_points_to_new_issue_form_with_bug_report_template(): void
    {
        $url = IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig());

        $this->assertStringStartsWith('https://github.com/psalm/psalm-plugin-laravel/issues/new?template=bug_report.md', $url);
    }

    #[Test]
    public function title_prefixes_plugin_initialization_error(): void
    {
        $title = $this->titleFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig()));

        $this->assertSame('Plugin initialization error: boom', $title);
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

        $title = $this->titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage), $this->defaultConfig()));

        $this->assertSame('Plugin initialization error: Class "Introspect" not found', $title);
    }

    #[Test]
    public function title_strips_windows_paths_containing_spaces(): void
    {
        $rawMessage = 'Class "Foo" not found in C:\\Users\\John Doe\\project\\src\\File.php:12';

        $title = $this->titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage), $this->defaultConfig()));

        $this->assertSame('Plugin initialization error: Class "Foo" not found', $title);
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
        $title = $this->titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage), $this->defaultConfig()));

        $this->assertSame('Plugin initialization error: ' . $expectedTail, $title);
    }

    #[Test]
    public function title_does_not_strip_non_php_colon_prefixes(): void
    {
        $title = $this->titleFrom(IssueUrlGenerator::generate(new \RuntimeException('Class not found: Foo'), $this->defaultConfig()));

        $this->assertSame('Plugin initialization error: Class not found: Foo', $title);
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

        $title = $this->titleFrom(IssueUrlGenerator::generate(new \RuntimeException($rawMessage), $this->defaultConfig()));
        $expectedTitle = 'Plugin initialization error: Class "Introspect" not found';

        $this->assertSame($expectedTitle, $title);
        $this->assertLessThan(\strlen($rawMessage), \strlen($expectedTitle));
    }

    #[Test]
    public function body_includes_fenced_trace_block(): void
    {
        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig()));

        $this->assertStringContainsString("```\n", $body);
        $this->assertStringContainsString('RuntimeException', $body);
        $this->assertStringContainsString('boom', $body);
    }

    #[Test]
    public function body_lists_plugin_version_from_installed_versions(): void
    {
        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig()));

        // psalm/plugin-laravel is this very package — always resolvable during test runs.
        $this->assertStringContainsString('**Versions:**', $body);
        $this->assertStringContainsString('- psalm/plugin-laravel:', $body);
    }

    #[Test]
    public function body_includes_plugin_configuration_section_with_default_values(): void
    {
        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig()));

        $this->assertStringContainsString('**Plugin configuration:**', $body);
        $this->assertStringContainsString('- modelPropertiesColumnFallback: migrations', $body);
        $this->assertStringContainsString('- resolveDynamicWhereClauses: true', $body);
        $this->assertStringContainsString('- findMissingTranslations: false', $body);
        $this->assertStringContainsString('- findMissingViews: false', $body);
        $this->assertStringContainsString('- cachePath:', $body);
        $this->assertStringContainsString('- failOnInternalError: false', $body);
    }

    #[Test]
    public function body_reflects_overridden_plugin_configuration_values(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<modelProperties columnFallback="none" />'
            . '<resolveDynamicWhereClauses value="false" />'
            . '<findMissingTranslations value="true" />'
            . '<findMissingViews value="true" />'
            . '<failOnInternalError value="true" />'
            . '</pluginClass>',
        );
        $config = PluginConfig::fromXml($xml);

        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $config));

        $this->assertStringContainsString('- modelPropertiesColumnFallback: none', $body);
        $this->assertStringContainsString('- resolveDynamicWhereClauses: false', $body);
        $this->assertStringContainsString('- findMissingTranslations: true', $body);
        $this->assertStringContainsString('- findMissingViews: true', $body);
        $this->assertStringContainsString('- failOnInternalError: true', $body);
    }

    /**
     * An empty configDirectories list is the implicit-default case (no XML opt-in).
     * Triage needs to see "default" rather than "[]" so the bug-report reader knows
     * the plugin used config_path() at runtime, not literally an empty list.
     */
    #[Test]
    public function body_renders_empty_config_directories_as_default(): void
    {
        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig()));

        $this->assertStringContainsString('- configDirectories: [] (default: config_path())', $body);
    }

    /**
     * Non-empty configDirectories render comma-separated, with each entry passed through
     * sanitizeCachePath(). Relative entries have no prefix to collapse and pass through
     * unchanged; absolute entries under $HOME, cwd, or tmp must collapse to anonymised
     * forms so the bug-report body does not leak filesystem layout.
     *
     * Uses sys_get_temp_dir() rather than cwd because cwd reliably appears elsewhere in
     * the rendered body (RuntimeException trace frames pointing into tests/), so a
     * "body does not contain $cwd" assertion would always fail. The temp-dir branch of
     * sanitizeCachePath is equivalent — both exercise the str_starts_with prefix collapse.
     */
    #[Test]
    public function body_anonymises_absolute_config_directory_paths(): void
    {
        $tmp = \sys_get_temp_dir();
        $absoluteUnderTmp = $tmp . \DIRECTORY_SEPARATOR . 'fake' . \DIRECTORY_SEPARATOR . 'Config';

        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="' . \htmlspecialchars($absoluteUnderTmp, \ENT_XML1) . '" />'
            . '<configDirectory name="packages/*/config" />'
            . '</pluginClass>',
        );
        $config = PluginConfig::fromXml($xml);

        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $config));

        // Absolute entry collapses to "<tmp>/fake/Config" via sanitizeCachePath's tmp branch.
        // Relative "packages/*/config" has no prefix to collapse and passes through.
        $this->assertStringContainsString(
            '- configDirectories: [<tmp>' . \DIRECTORY_SEPARATOR . 'fake' . \DIRECTORY_SEPARATOR . 'Config, packages/*/config]',
            $body,
        );
        $this->assertStringNotContainsString($absoluteUnderTmp, $body);
    }

    /**
     * Vendor fallback: an absolute prefix containing a vendor/ segment collapses
     * to a relative vendor/... path — useful for the uncommon case where the
     * cache sits inside a checkout rather than under cwd / tmp / HOME.
     */
    #[Test]
    #[IgnoreDeprecations]
    public function body_sanitises_cache_path_under_vendor_prefix(): void
    {
        $body = $this->bodyFromCachePath('/nowhere/project/vendor/psalm-cache/plugin-laravel');

        $this->assertStringContainsString('- cachePath: vendor/psalm-cache/plugin-laravel', $body);
        $this->assertStringNotContainsString('/nowhere/project', $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function body_sanitises_cache_path_under_cwd_prefix(): void
    {
        $cwd = \getcwd();
        $this->assertIsString($cwd);
        $cachePath = $cwd . \DIRECTORY_SEPARATOR . '.psalm-cache' . \DIRECTORY_SEPARATOR . 'plugin-laravel';

        $body = $this->bodyFromCachePath($cachePath);

        $expected = '- cachePath: .' . \DIRECTORY_SEPARATOR . '.psalm-cache' . \DIRECTORY_SEPARATOR . 'plugin-laravel';
        $this->assertStringContainsString($expected, $body);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function body_sanitises_cache_path_under_temp_dir_prefix(): void
    {
        $tmp = \sys_get_temp_dir();
        $cachePath = \rtrim($tmp, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . 'psalm-laravel-unit-test';

        $body = $this->bodyFromCachePath($cachePath);

        $this->assertStringContainsString('- cachePath: <tmp>' . \DIRECTORY_SEPARATOR . 'psalm-laravel-unit-test', $body);
    }

    /**
     * Path under $HOME (but not under cwd) collapses to "~/..." so the reporter's
     * username does not leak into the bug-report body. This covers the realistic
     * default when Psalm's cache directory resolves outside the project root
     * (e.g. global cache under the user's home).
     */
    #[Test]
    #[IgnoreDeprecations]
    public function body_sanitises_cache_path_under_home_prefix(): void
    {
        $home = \getenv('HOME');
        if (!\is_string($home) || $home === '') {
            self::markTestSkipped('$HOME is not available on this platform');
        }

        // Choose a sibling of the project root so cwd does NOT also match — the
        // cwd check would otherwise fire first and produce "./..." instead of "~/...".
        $cachePath = \rtrim($home, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR . '.psalm-laravel-test-cache';

        $body = $this->bodyFromCachePath($cachePath);

        $this->assertStringContainsString('- cachePath: ~' . \DIRECTORY_SEPARATOR . '.psalm-laravel-test-cache', $body);
    }

    /**
     * Reflection-backed guard: every public property on PluginConfig must appear
     * as a bullet in the rendered Plugin-configuration section. Catches the
     * scenario where a future PluginConfig field is added but the renderer in
     * IssueUrlGenerator::pluginConfigLines() is not updated, silently omitting
     * plugin-relevant state from bug reports.
     */
    #[Test]
    public function body_renders_every_public_plugin_config_field(): void
    {
        $reflection = new \ReflectionClass(PluginConfig::class);
        $publicProperties = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $publicProperties[] = $property->getName();
        }

        $this->assertNotEmpty($publicProperties, 'PluginConfig should expose at least one public property');

        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig()));

        foreach ($publicProperties as $name) {
            $this->assertStringContainsString("- {$name}: ", $body, "Plugin configuration section is missing '{$name}'");
        }
    }

    #[Test]
    public function body_renders_plugin_configuration_between_versions_and_trace(): void
    {
        $body = $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), $this->defaultConfig()));

        $versionsPos = \strpos($body, '**Versions:**');
        $configPos = \strpos($body, '**Plugin configuration:**');
        $fencePos = \strpos($body, '```');

        $this->assertIsInt($versionsPos);
        $this->assertIsInt($configPos);
        $this->assertIsInt($fencePos);
        $this->assertLessThan($configPos, $versionsPos);
        $this->assertLessThan($fencePos, $configPos);
    }

    /**
     * The trace-specific tests exercise the private `sanitizeTrace()` directly so the
     * synthetic absolute paths we want to observe are not entangled with PHPUnit's own
     * stack-frame-argument truncation, which otherwise rewrites them as `/foo/bar/ap...`
     * before the sanitizer can see a `vendor/` or `src/` segment.
     */
    private function invokeSanitizeTrace(string $input): string
    {
        $method = new \ReflectionMethod(IssueUrlGenerator::class, 'sanitizeTrace');

        return (string) $method->invoke(null, $input);
    }

    #[Test]
    public function trace_collapses_absolute_vendor_path(): void
    {
        $input = '#0 /home/bob/app/vendor/vimeo/psalm/src/Foo.php(99): Bar->baz()';

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame('#0 vendor/vimeo/psalm/src/Foo.php(99): Bar->baz()', $output);
    }

    #[Test]
    public function trace_collapses_absolute_src_path(): void
    {
        $input = '#0 /home/bob/psalm-plugin-laravel/src/Plugin.php(697): X->y()';

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame('#0 src/Plugin.php(697): X->y()', $output);
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

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame('#0 vendor/laravel/framework/src/Illuminate/F.php(9): A->b()', $output);
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

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame('#0 vendor/laravel/framework/src/Illuminate/Foundation/AliasLoader.php(79): X->y()', $output);
        $this->assertStringNotContainsString('vendorsrc', $output);
    }

    #[Test]
    public function trace_sanitises_quoted_path_arguments_in_stack_frame(): void
    {
        $input = "#0 /a/vendor/b.php(9): X->y('/dev/vendor/z.php', 79)";

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame("#0 vendor/b.php(9): X->y('vendor/z.php', 79)", $output);
    }

    #[Test]
    public function trace_collapses_windows_backslash_path(): void
    {
        $input = '#0 C:\\Users\\carol\\src\\app\\vendor\\laravel\\framework\\src\\Foo.php(1): X->y()';

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame('#0 vendor\\laravel\\framework\\src\\Foo.php(1): X->y()', $output);
    }

    /**
     * Regression: nested `.../src/.../src/...` paths with no vendor segment.
     * A non-greedy middle in the src pass would stop at the first `src/` in the
     * absolute prefix and leak "project/src/...". The greedy middle prefers the
     * last `src/` segment and collapses the whole prefix.
     */
    #[Test]
    public function trace_collapses_nested_src_paths_without_vendor_segment(): void
    {
        $input = '#0 /Users/alice/src/project/src/Plugin.php(42): X->y()';

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame('#0 src/Plugin.php(42): X->y()', $output);
    }

    #[Test]
    public function trace_only_collapses_src_when_no_vendor_segment_exists(): void
    {
        // When no vendor/ segment exists, the src pass handles the rewrite.
        $input = '#0 /home/u/project/src/Plugin.php(42): X->y()';

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame('#0 src/Plugin.php(42): X->y()', $output);
    }

    #[Test]
    public function trace_leaves_already_relative_paths_untouched(): void
    {
        $input = '#0 vendor/already/relative.php(1): X->y()';

        $output = $this->invokeSanitizeTrace($input);

        $this->assertSame($input, $output);
    }

    private function defaultConfig(): PluginConfig
    {
        return PluginConfig::fromXml(null);
    }

    /**
     * Pin `cachePath` via the env var (the only writable path since PluginConfig's
     * constructor is private) and return the URL body for a throwaway throwable.
     */
    private function bodyFromCachePath(string $cachePath): string
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=' . $cachePath);

        return $this->bodyFrom(IssueUrlGenerator::generate(new \RuntimeException('boom'), PluginConfig::fromXml(null)));
    }

    private function titleFrom(string $url): string
    {
        $query = \parse_url($url, \PHP_URL_QUERY);
        $this->assertIsString($query);

        $params = [];
        \parse_str($query, $params);

        $this->assertArrayHasKey('title', $params);
        $this->assertIsString($params['title']);

        return $params['title'];
    }

    private function bodyFrom(string $url): string
    {
        $query = \parse_url($url, \PHP_URL_QUERY);
        $this->assertIsString($query);

        $params = [];
        \parse_str($query, $params);

        $this->assertArrayHasKey('body', $params);
        $this->assertIsString($params['body']);

        return $params['body'];
    }
}
