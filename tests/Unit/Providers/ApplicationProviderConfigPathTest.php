<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;

/**
 * Regression coverage for issue #940 — when Psalm runs against a Laravel
 * **package** (not an app with `bootstrap/app.php`), `ApplicationProvider` falls
 * back to Orchestra Testbench. Without retargeting, the booted app's
 * `config_path()` pointed at `vendor/orchestra/testbench-core/laravel/config`
 * and every `env()` call in the package's own `config/*.php` was flagged.
 *
 * The retargeting fires only in the Testbench-fallback branch. To make the
 * assertion order-independent, we reset `ApplicationProvider`'s memoised state
 * before booting and `chdir()` to the plugin repo root (derived from `__DIR__`,
 * not the inherited process cwd) so the result does not depend on the working
 * directory of the test runner or on whether an earlier test booted the app.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/940
 */
#[CoversClass(ApplicationProvider::class)]
final class ApplicationProviderConfigPathTest extends TestCase
{
    private string $originalCwd;

    /** @var array<string, mixed> */
    private array $originalState = [];

    protected function setUp(): void
    {
        parent::setUp();

        $cwd = \getcwd();
        \assert(\is_string($cwd), 'getcwd() must succeed before the test');
        $this->originalCwd = $cwd;

        // Snapshot ApplicationProvider's memoised state so a previous test that
        // booted the app from a different cwd cannot leak its `configPath()` into
        // this one.
        $this->originalState = $this->snapshotApplicationProviderState();
        $this->reflectProperty('app')->setValue(null, null);
        $this->reflectProperty('booted')->setValue(null, false);
        $this->reflectProperty('bootFailure')->setValue(null, null);
        $this->reflectProperty('bootstrapError')->setValue(null, null);
    }

    protected function tearDown(): void
    {
        \chdir($this->originalCwd);

        foreach ($this->originalState as $property => $value) {
            $this->reflectProperty($property)->setValue(null, $value);
        }

        parent::tearDown();
    }

    #[Test]
    public function config_path_resolves_to_project_root_under_testbench_fallback(): void
    {
        // Anchor at the plugin repo root deterministically, not at the inherited
        // process cwd. `tests/Unit/Providers/` is 3 levels deep from the root.
        $repoRoot = \dirname(__DIR__, 3);
        \chdir($repoRoot);

        $this->assertFileExists(
            $repoRoot . \DIRECTORY_SEPARATOR . 'composer.json',
            'pre-condition: the auto-detection requires composer.json at the anchor',
        );

        // Boots via Testbench because the plugin repo has no `bootstrap/app.php`;
        // that fallback branch retargets config_path() at the package root.
        $app = ApplicationProvider::getApp();

        $this->assertSame(
            $repoRoot . \DIRECTORY_SEPARATOR . 'config',
            $app->configPath(),
            'config_path() must resolve to <project>/config so NoEnvOutsideConfig '
                . 'sees the package config files instead of Testbench skeleton.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotApplicationProviderState(): array
    {
        return [
            'app' => $this->reflectProperty('app')->getValue(),
            'bootMode' => $this->reflectProperty('bootMode')->getValue(),
            'bootPath' => $this->reflectProperty('bootPath')->getValue(),
            'bootFailure' => $this->reflectProperty('bootFailure')->getValue(),
            'bootstrapError' => $this->reflectProperty('bootstrapError')->getValue(),
            'booted' => $this->reflectProperty('booted')->getValue(),
        ];
    }

    private function reflectProperty(string $name): \ReflectionProperty
    {
        return new \ReflectionProperty(ApplicationProvider::class, $name);
    }
}
