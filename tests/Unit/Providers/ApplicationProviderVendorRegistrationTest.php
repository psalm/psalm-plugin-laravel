<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures\BindingServiceProvider;
use Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures\ThrowingServiceProvider;

/**
 * Regression coverage for issue #942 — branch 3 of `ApplicationProvider::doGetApp()`
 * must discover and register vendor service providers (which Testbench would
 * otherwise filter out via `ignorePackageDiscoveriesFrom() === ['*']`), and a single
 * throwing provider must NOT prevent the rest from registering.
 *
 * We pin a fake project root via `APP_BASE_PATH` (Testbench's documented escape
 * hatch, also honored by {@see ApplicationProvider::resolveProjectRoot()}). The fake
 * root ships a synthetic `vendor/composer/installed.json` listing the throwing
 * fixture first and the binding fixture second — verifying both that exceptions
 * are swallowed AND that iteration continues past them.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/942
 */
#[CoversClass(ApplicationProvider::class)]
final class ApplicationProviderVendorRegistrationTest extends TestCase
{
    private string $fakeProjectRoot;

    private ?string $originalAppBasePath;

    /** @var array<string, mixed> */
    private array $originalState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppBasePath = $_ENV['APP_BASE_PATH'] ?? null;
        $this->originalState = [
            'app' => $this->reflectProperty('app')->getValue(),
            'booted' => $this->reflectProperty('booted')->getValue(),
        ];

        $this->fakeProjectRoot = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'psalm-plugin-laravel-test-' . \bin2hex(\random_bytes(6));
        $this->buildFakeProjectRoot();

        $_ENV['APP_BASE_PATH'] = $this->fakeProjectRoot;
        $this->reflectProperty('app')->setValue(null, null);
        $this->reflectProperty('booted')->setValue(null, false);
    }

    protected function tearDown(): void
    {
        if ($this->originalAppBasePath === null) {
            unset($_ENV['APP_BASE_PATH']);
        } else {
            $_ENV['APP_BASE_PATH'] = $this->originalAppBasePath;
        }

        $this->reflectProperty('app')->setValue(null, $this->originalState['app']);
        $this->reflectProperty('booted')->setValue(null, $this->originalState['booted']);

        $this->removeDirectory($this->fakeProjectRoot);

        parent::tearDown();
    }

    #[Test]
    public function discovered_vendor_provider_binding_survives_an_earlier_throwing_provider(): void
    {
        // ApplicationProvider::getApp() goes through doGetApp(). The plugin repo has no
        // bootstrap/app.php at the test's cwd nor at dirname(__DIR__, 5), so branch 3
        // fires and registerDiscoveredVendorProviders() runs against APP_BASE_PATH.
        $app = ApplicationProvider::getApp();

        $this->assertTrue(
            $app->bound(BindingServiceProvider::BINDING_KEY),
            'BindingServiceProvider must register even when ThrowingServiceProvider'
                . ' (listed earlier in installed.json) throws from register().'
                . ' The per-provider try/catch is the isolation contract for #942.',
        );

        $this->assertSame(
            BindingServiceProvider::BOUND_VALUE,
            $app->make(BindingServiceProvider::BINDING_KEY),
            'Resolving the bound key must return the value bound by the fixture provider.',
        );
    }

    private function buildFakeProjectRoot(): void
    {
        \mkdir($this->fakeProjectRoot . '/vendor/composer', 0o755, true);
        // Testbench anchors a number of writable paths at APP_BASE_PATH during boot
        // (config cache, route cache, etc.). Without `bootstrap/cache/`, the framework
        // PackageManifest::write() throws BEFORE we even get to register vendor
        // providers — masking the behaviour we want to test.
        \mkdir($this->fakeProjectRoot . '/bootstrap/cache', 0o755, true);
        \file_put_contents($this->fakeProjectRoot . '/composer.json', '{}');

        $installedJson = [
            'packages' => [
                // Throwing first: verifies iteration continues past failures.
                [
                    'name' => 'psalm-plugin-laravel/test-throwing',
                    'extra' => ['laravel' => ['providers' => [ThrowingServiceProvider::class]]],
                ],
                [
                    'name' => 'psalm-plugin-laravel/test-binding',
                    'extra' => ['laravel' => ['providers' => [BindingServiceProvider::class]]],
                ],
            ],
        ];

        \file_put_contents(
            $this->fakeProjectRoot . '/vendor/composer/installed.json',
            \json_encode($installedJson, \JSON_THROW_ON_ERROR),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $this->assertInstanceOf(\SplFileInfo::class, $entry);
            $entryPath = $entry->getPathname();

            if ($entry->isDir()) {
                @\rmdir($entryPath);
            } else {
                @\unlink($entryPath);
            }
        }

        @\rmdir($path);
    }

    private function reflectProperty(string $name): \ReflectionProperty
    {
        return new \ReflectionProperty(ApplicationProvider::class, $name);
    }
}
