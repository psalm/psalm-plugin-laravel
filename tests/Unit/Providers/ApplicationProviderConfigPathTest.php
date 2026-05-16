<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;

/**
 * Regression coverage for issue #940 — when Psalm runs against a Laravel
 * **package** (not an app with `bootstrap/app.php`), ApplicationProvider falls
 * back to Orchestra Testbench. Without retargeting, the booted app's
 * `config_path()` pointed at `vendor/orchestra/testbench-core/laravel/config`
 * and every `env()` call in the package's own `config/*.php` was flagged.
 *
 * The retargeting fires only in the Testbench-fallback branch. This test
 * suite's `composer test:unit` runs from a directory containing a
 * `composer.json` (the plugin's own root or worktree), so the auto-detection
 * always finds the project root and the assertion is deterministic.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/940
 */
#[CoversClass(ApplicationProvider::class)]
final class ApplicationProviderConfigPathTest extends TestCase
{
    #[Test]
    public function config_path_resolves_to_project_root_under_testbench_fallback(): void
    {
        // Boots via Testbench because the test process is run from the plugin's
        // own directory, which has no `bootstrap/app.php`. Branch 3 fires.
        $app = ApplicationProvider::getApp();

        $cwd = \getcwd();
        $this->assertIsString($cwd, 'getcwd() must succeed in the test environment');
        $this->assertFileExists($cwd . \DIRECTORY_SEPARATOR . 'composer.json', 'pre-condition: the auto-detection requires composer.json at cwd');

        $this->assertSame($cwd . \DIRECTORY_SEPARATOR . 'config', $app->configPath(), 'config_path() must resolve to <project>/config so NoEnvOutsideConfig '
            . 'sees the package config files instead of Testbench skeleton.');
    }
}
