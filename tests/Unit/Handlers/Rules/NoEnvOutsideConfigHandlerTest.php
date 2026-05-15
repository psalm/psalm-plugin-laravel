<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Rules\NoEnvOutsideConfigHandler;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\StatementsSource;

/**
 * Tests use real filesystem fixtures because the handler resolves config directories
 * via realpath() + is_dir() at init() time. Faking paths via DIRECTORY_SEPARATOR strings
 * (the previous approach) silently passed because the old implementation only matched
 * on path segments — that's exactly the gap issue #858 addresses.
 */
#[CoversClass(NoEnvOutsideConfigHandler::class)]
final class NoEnvOutsideConfigHandlerTest extends TestCase
{
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . \uniqid('psalm-laravel-noenvtest-', true);

        \mkdir($this->tempRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        // init([]) doubles as a reset — empties resolved state without warning
        // (the typo-warning needs both a non-empty input AND a Progress).
        NoEnvOutsideConfigHandler::init([]);
        $this->removeRecursively($this->tempRoot);

        parent::tearDown();
    }

    #[Test]
    public function returns_env_function_id(): void
    {
        $this->assertSame(['env'], NoEnvOutsideConfigHandler::getFunctionIds());
    }

    #[Test]
    public function skips_files_inside_default_config_directory(): void
    {
        $configDir = $this->makeDir('config');
        NoEnvOutsideConfigHandler::init([$configDir]);

        $event = $this->makeEvent($configDir . \DIRECTORY_SEPARATOR . 'app.php');

        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_files_in_nested_config_subdirectory(): void
    {
        $configDir = $this->makeDir('config');
        $this->makeDir('config/services');
        NoEnvOutsideConfigHandler::init([$configDir]);

        $event = $this->makeEvent($configDir . \DIRECTORY_SEPARATOR . 'services' . \DIRECTORY_SEPARATOR . 'api.php');

        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_test_files_regardless_of_config_directory(): void
    {
        $configDir = $this->makeDir('config');
        $testsDir = $this->makeDir('tests');
        NoEnvOutsideConfigHandler::init([$configDir]);

        $event = $this->makeEvent($testsDir . \DIRECTORY_SEPARATOR . 'Unit' . \DIRECTORY_SEPARATOR . 'MyTest.php');

        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    /**
     * Acceptance criterion from issue #858: BookStack uses `app/Config/` instead of `config/`.
     * With the user-configured directory, env() calls there are no longer flagged.
     */
    #[Test]
    public function skips_user_configured_directory(): void
    {
        $bookstackConfig = $this->makeDir('app/Config');
        NoEnvOutsideConfigHandler::init([$bookstackConfig]);

        $event = $this->makeEvent($bookstackConfig . \DIRECTORY_SEPARATOR . 'app.php');

        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    /**
     * Acceptance criterion from issue #858: glob patterns expand at init() time and
     * match every package's config directory in a monorepo.
     */
    #[Test]
    public function skips_glob_pattern_matched_directories(): void
    {
        $formsConfig = $this->makeDir('packages/forms/config');
        $tablesConfig = $this->makeDir('packages/tables/config');

        NoEnvOutsideConfigHandler::init([$this->tempRoot . \DIRECTORY_SEPARATOR . 'packages/*/config']);

        $eventForms = $this->makeEvent($formsConfig . \DIRECTORY_SEPARATOR . 'forms.php');
        $eventTables = $this->makeEvent($tablesConfig . \DIRECTORY_SEPARATOR . 'tables.php');

        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($eventForms));
        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($eventTables));
    }

    /**
     * Regression for the glob-metacharacter bug: a literal path like `dev[work]/config`
     * must resolve via the is_dir() fast path, not through glob() — glob would
     * interpret `[work]` as a character class and return no matches.
     */
    #[Test]
    public function skips_literal_path_with_glob_metacharacters(): void
    {
        $configDir = $this->makeDir('dev[work]/config');
        NoEnvOutsideConfigHandler::init([$configDir]);

        $event = $this->makeEvent($configDir . \DIRECTORY_SEPARATOR . 'app.php');

        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    /**
     * Glob patterns may be relative to the cwd (Larastan convention). Verifies the
     * fallback to glob() runs when the literal path isn't a directory.
     */
    #[Test]
    public function skips_relative_glob_pattern(): void
    {
        // makeDir() returns realpath() so it survives the symlinked tmp dir on macOS
        // (/var/folders/... → /private/var/folders/...).
        $formsConfig = $this->makeDir('packages/forms/config');
        $tempRootReal = \realpath($this->tempRoot);
        \assert(\is_string($tempRootReal));

        $cwd = \getcwd();
        \assert(\is_string($cwd));

        try {
            \chdir($tempRootReal);
            NoEnvOutsideConfigHandler::init(['packages/*/config']);
        } finally {
            \chdir($cwd);
        }

        $event = $this->makeEvent($formsConfig . \DIRECTORY_SEPARATOR . 'forms.php');

        $this->assertNull(NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    /**
     * Reaching the emit branch calls IssueBuffer::accepts(), which delegates to
     * Config::getInstance() and throws UnexpectedValueException('No config initialized')
     * when Psalm isn't bootstrapped. Asserting that exception confirms the handler
     * actually reached issue emission, rather than throwing somewhere earlier.
     */
    #[Test]
    public function rejects_application_code_outside_config_directory(): void
    {
        $configDir = $this->makeDir('config');
        $appDir = $this->makeDir('app/Services');
        NoEnvOutsideConfigHandler::init([$configDir]);

        $event = $this->makeEvent($appDir . \DIRECTORY_SEPARATOR . 'PaymentService.php');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/config.*initializ|initializ.*config/i');

        NoEnvOutsideConfigHandler::getFunctionReturnType($event);
    }

    /**
     * Without an opt-in, vendor packages no longer suppress the issue — closing the
     * "false negative" gap from issue #858. Users wanting to allow vendor configs
     * must add `<configDirectory name="vendor/foo/bar/config" />`.
     */
    #[Test]
    public function rejects_vendor_package_config_when_not_opted_in(): void
    {
        $configDir = $this->makeDir('config');
        $vendorConfig = $this->makeDir('vendor/spatie/laravel-backup/config');
        NoEnvOutsideConfigHandler::init([$configDir]);

        $event = $this->makeEvent($vendorConfig . \DIRECTORY_SEPARATOR . 'backup.php');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/config.*initializ|initializ.*config/i');

        NoEnvOutsideConfigHandler::getFunctionReturnType($event);
    }

    /**
     * Substring look-alikes (e.g. `configuration/`, `myconfig/`) — the old
     * str_contains heuristic could be tricked here; str_starts_with cannot.
     */
    #[Test]
    public function rejects_substring_lookalike_directories(): void
    {
        $configDir = $this->makeDir('config');
        $lookalikeDir = $this->makeDir('app/configuration');
        NoEnvOutsideConfigHandler::init([$configDir]);

        $event = $this->makeEvent($lookalikeDir . \DIRECTORY_SEPARATOR . 'Foo.php');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/config.*initializ|initializ.*config/i');

        NoEnvOutsideConfigHandler::getFunctionReturnType($event);
    }

    /**
     * Non-existent or non-directory entries are silently skipped — the plugin can't
     * tell a glob with no matches yet from a misconfigured path. With no resolvable
     * directories, no path matches, so application files emit the issue as expected.
     */
    #[Test]
    public function init_drops_non_existent_paths(): void
    {
        $appDir = $this->makeDir('app');
        NoEnvOutsideConfigHandler::init([
            $this->tempRoot . \DIRECTORY_SEPARATOR . 'does-not-exist',
            $this->tempRoot . \DIRECTORY_SEPARATOR . 'glob-with-no-matches/*',
        ]);

        $event = $this->makeEvent($appDir . \DIRECTORY_SEPARATOR . 'Foo.php');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/config.*initializ|initializ.*config/i');

        NoEnvOutsideConfigHandler::getFunctionReturnType($event);
    }

    /**
     * If the user typos every <configDirectory> entry, the resolved list is empty and
     * every env() call would be flagged. Surface a warning instead of failing silently.
     */
    #[Test]
    public function init_warns_when_non_empty_input_resolves_to_empty(): void
    {
        $progress = new RecordingProgress();

        NoEnvOutsideConfigHandler::init(
            [$this->tempRoot . \DIRECTORY_SEPARATOR . 'cofnig-typo'],
            $progress,
        );

        $this->assertSame(1, $progress->warningCount, 'expected exactly one warning');
        $this->assertStringContainsString('NoEnvOutsideConfig', $progress->lastWarning);
        $this->assertStringContainsString('cofnig-typo', $progress->lastWarning);
    }

    /**
     * A glob that matches nothing (or any other silent drop) is normal and must not warn
     * as long as at least one entry resolves successfully — projects often configure
     * monorepo glob patterns for `packages/<wildcard>/config` directories that may be
     * empty initially.
     */
    #[Test]
    public function init_does_not_warn_when_at_least_one_entry_resolves(): void
    {
        $configDir = $this->makeDir('config');
        $progress = new RecordingProgress();

        NoEnvOutsideConfigHandler::init(
            [$configDir, $this->tempRoot . \DIRECTORY_SEPARATOR . 'unmatched/*/config'],
            $progress,
        );

        $this->assertSame(0, $progress->warningCount, 'expected no warning when at least one entry resolves');
    }

    /**
     * Empty input is a legitimate "reset" path used by tests; init() must not warn.
     */
    #[Test]
    public function init_does_not_warn_on_empty_input(): void
    {
        $progress = new RecordingProgress();

        NoEnvOutsideConfigHandler::init([], $progress);

        $this->assertSame(0, $progress->warningCount);
    }

    private function makeDir(string $relativePath): string
    {
        $full = $this->tempRoot
            . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, $relativePath);

        if (!\is_dir($full)) {
            \mkdir($full, 0o777, true);
        }

        $real = \realpath($full);
        \assert(\is_string($real), "Failed to realpath '{$full}'");

        return $real;
    }

    private function makeEvent(string $filePath): FunctionReturnTypeProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));
        $source->method('getSuppressedIssues')->willReturn([]);

        $funcCall = new FuncCall(new Name('env'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);

        return new FunctionReturnTypeProviderEvent(
            $source,
            'env',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );
    }

    private function removeRecursively(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entry->isDir() ? \rmdir($entry->getPathname()) : \unlink($entry->getPathname());
        }

        \rmdir($path);
    }
}

/**
 * Test-only Progress that records warnings without writing to STDERR.
 * Other Progress hooks are no-ops because the handler only uses warning().
 */
final class RecordingProgress extends \Psalm\Progress\Progress
{
    public int $warningCount = 0;

    public string $lastWarning = '';

    #[\Override]
    public function debug(string $message): void {}

    #[\Override]
    public function startPhase(\Psalm\Progress\Phase $phase, int $threads = 1): void {}

    #[\Override]
    public function expand(int $number_of_tasks): void {}

    #[\Override]
    public function taskDone(int $level): void {}

    #[\Override]
    public function finish(): void {}

    #[\Override]
    public function alterFileDone(string $file_name): void {}

    #[\Override]
    public function write(string $message): void {}

    #[\Override]
    public function warning(string $message): void
    {
        $this->warningCount++;
        $this->lastWarning = $message;
    }
}
