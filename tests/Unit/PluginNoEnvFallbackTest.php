<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Rules\NoEnvOutsideConfigHandler;
use Psalm\LaravelPlugin\Plugin;
use Psalm\LaravelPlugin\PluginConfig;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Progress\Progress;
use Psalm\StatementsSource;

/**
 * Plugin-level coverage for the implicit `<configDirectory>` fallback.
 *
 * Handler unit tests (NoEnvOutsideConfigHandlerTest) cover the resolution
 * mechanism. Those tests construct the list themselves, so they cannot catch a
 * regression where Plugin stops including `getcwd()/config` in the fallback.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/940
 */
#[CoversClass(Plugin::class)]
final class PluginNoEnvFallbackTest extends TestCase
{
    private string $tempRoot;

    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        // The plugin's getApp() requires bootApp() to have run; mirrors the
        // FacadeMapProviderTest setup pattern.
        ApplicationProvider::bootApp();

        $cwd = \getcwd();
        \assert(\is_string($cwd), 'getcwd() must return a string in tests');
        $this->originalCwd = $cwd;

        $this->tempRoot = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . \uniqid('psalm-laravel-940-', true);

        \mkdir($this->tempRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        \chdir($this->originalCwd);

        // init([]) doubles as a reset — empties resolved state without warning.
        NoEnvOutsideConfigHandler::init([]);

        $this->removeRecursively($this->tempRoot);

        parent::tearDown();
    }

    /**
     * The package-analysis regression: without `<configDirectory>` opt-in, every
     * env() call inside the package's own `config/` was being flagged because the
     * fallback resolved to Testbench's vendored config dir. With the cwd/config
     * anchor, the package's actual config dir is also skipped.
     */
    #[Test]
    public function fallback_includes_cwd_config_for_packages(): void
    {
        $packageConfigDir = $this->makeDir('config');

        \chdir($this->tempRoot);

        $this->invokeInitNoEnvHandler(PluginConfig::fromXml(null), new RecordingProgress());

        $event = $this->makeEvent($packageConfigDir . \DIRECTORY_SEPARATOR . 'liap.php');

        $this->assertNull(
            NoEnvOutsideConfigHandler::getFunctionReturnType($event),
            "env() inside the package's own config/ must be skipped under the implicit fallback",
        );
    }

    /**
     * Projects with a non-standard layout (no `config/` at the project root) miss
     * the cwd anchor. Emit a single one-shot warning pointing them at the
     * `<configDirectory>` opt-in so the silent miss is surfaced.
     */
    #[Test]
    public function fallback_warns_when_cwd_has_no_config_dir(): void
    {
        \chdir($this->tempRoot);
        $progress = new RecordingProgress();

        $this->invokeInitNoEnvHandler(PluginConfig::fromXml(null), $progress);

        $this->assertSame(1, $progress->warningCount, 'expected exactly one warning');
        $this->assertStringContainsString('configDirectory', $progress->lastWarning);
        $this->assertStringContainsString('psalm-plugin-laravel/config/#configdirectory', $progress->lastWarning);
    }

    /**
     * When the user has opted in via `<configDirectory>`, the cwd/config anchor
     * is irrelevant and the diagnostic warning must not fire. This guards against
     * a regression where the warning unconditionally fires.
     */
    #[Test]
    public function does_not_warn_when_user_configured_explicit_directory(): void
    {
        $appConfigDir = $this->makeDir('app/Config');
        \chdir($this->tempRoot);

        $xml = new \SimpleXMLElement(
            '<pluginClass><configDirectory name="' . \htmlspecialchars($appConfigDir, \ENT_XML1) . '" /></pluginClass>',
        );

        $progress = new RecordingProgress();

        $this->invokeInitNoEnvHandler(PluginConfig::fromXml($xml), $progress);

        $this->assertSame(0, $progress->warningCount, 'opt-in must silence the fallback diagnostic');
    }

    private function invokeInitNoEnvHandler(PluginConfig $pluginConfig, Progress $progress): void
    {
        // ReflectionMethod::invoke() can call private methods directly on PHP 8.1+;
        // setAccessible() is a no-op and no longer required.
        $method = new \ReflectionMethod(Plugin::class, 'initNoEnvOutsideConfigHandler');
        $method->invoke(new Plugin(), $pluginConfig, $progress);
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
 * Mirrors the sibling helper in NoEnvOutsideConfigHandlerTest; kept local so
 * the two test files stay independent (different namespaces; no collision).
 */
final class RecordingProgress extends Progress
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
