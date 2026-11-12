<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Plugin;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * #1635: a deprecated setting must warn without aborting the run, and stay visible under
 * `--no-progress`. Only a real Psalm subprocess runs both its ErrorHandler and the plugin entry point.
 */
#[CoversClass(Plugin::class)]
#[Group('subprocess')]
final class PluginDeprecationVisibilityTest extends TestCase
{
    use CopiesFixtureDirectories;

    private ?string $cacheDir = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->cacheDir !== null && \is_dir($this->cacheDir)) {
            $this->removeDirectory($this->cacheDir);
        }
    }

    #[Test]
    public function the_deprecated_cache_path_env_var_warns_once_on_stderr_and_the_run_succeeds(): void
    {
        $root = \dirname(__DIR__, 2);
        $this->cacheDir = \sys_get_temp_dir() . '/psalm-laravel-deprecation-' . \bin2hex(\random_bytes(4));

        $process = new Process(
            [\PHP_BINARY, $root . '/vendor/bin/psalm', '--no-cache', '--no-progress', '--threads=1', '--output-format=json', $root . '/src/Internal/PluginWarning.php'],
            $root,
            ['PSALM_LARAVEL_PLUGIN_CACHE_PATH' => $this->cacheDir],
        );
        $process->setTimeout(300);
        $process->run();

        $stderr = $process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $stderr);
        $this->assertSame(1, \substr_count($stderr, 'PSALM_LARAVEL_PLUGIN_CACHE_PATH is deprecated'), $stderr);
        // stdout carries the JSON report, so the warning must not leak into it.
        $this->assertSame('[]', \trim($process->getOutput()));
    }
}
