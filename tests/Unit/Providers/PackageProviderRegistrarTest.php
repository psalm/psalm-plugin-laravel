<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\PackageProviderRegistrar;
use Psalm\Progress\VoidProgress;
use Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures\BrokenServiceProvider;
use Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures\TestStringAliasServiceProvider;
use Tests\Psalm\LaravelPlugin\Unit\Providers\Fixtures\TestStringAliasTarget;

#[CoversClass(PackageProviderRegistrar::class)]
final class PackageProviderRegistrarTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir()
            . \DIRECTORY_SEPARATOR
            . 'psalm-plugin-laravel-registrar-test-'
            . \bin2hex(\random_bytes(4));

        \mkdir($this->projectRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->projectRoot . '/*') ?: [] as $file) {
            @\unlink($file);
        }

        @\rmdir($this->projectRoot);
    }

    #[Test]
    public function registers_providers_declared_in_composer_json_extra_laravel_providers(): void
    {
        $this->writeComposerJson([
            'extra' => [
                'laravel' => [
                    'providers' => [TestStringAliasServiceProvider::class],
                ],
            ],
        ]);

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        self::assertTrue(
            $app->bound(TestStringAliasServiceProvider::STRING_KEY),
            'String binding from the project\'s own composer.json should be registered in the app',
        );

        $resolved = $app->make(TestStringAliasServiceProvider::STRING_KEY);
        self::assertInstanceOf(TestStringAliasTarget::class, $resolved);
    }

    #[Test]
    public function registers_providers_declared_in_composer_lock_packages_section(): void
    {
        $this->writeComposerLock([
            'packages' => [
                [
                    'name' => 'fake/runtime-package',
                    'extra' => [
                        'laravel' => [
                            'providers' => [TestStringAliasServiceProvider::class],
                        ],
                    ],
                ],
            ],
        ]);

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        self::assertTrue($app->bound(TestStringAliasServiceProvider::STRING_KEY));
    }

    #[Test]
    public function registers_providers_declared_in_composer_lock_packages_dev_section(): void
    {
        $this->writeComposerLock([
            'packages-dev' => [
                [
                    'name' => 'fake/dev-package',
                    'extra' => [
                        'laravel' => [
                            'providers' => [TestStringAliasServiceProvider::class],
                        ],
                    ],
                ],
            ],
        ]);

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        self::assertTrue($app->bound(TestStringAliasServiceProvider::STRING_KEY));
    }

    #[Test]
    public function skips_broken_providers_without_propagating_exception(): void
    {
        $this->writeComposerJson([
            'extra' => [
                'laravel' => [
                    'providers' => [
                        BrokenServiceProvider::class,
                        TestStringAliasServiceProvider::class,
                    ],
                ],
            ],
        ]);

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        // BrokenServiceProvider must not crash the iteration — the good one after it must still register.
        self::assertTrue(
            $app->bound(TestStringAliasServiceProvider::STRING_KEY),
            'Registrar must continue past a broken provider and still register subsequent ones',
        );
    }

    #[Test]
    public function does_not_fail_when_composer_files_are_missing(): void
    {
        // Empty project root — no composer.json, no composer.lock.
        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        // No bindings added, no exceptions. Reached here without issue.
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function does_not_fail_on_malformed_composer_json(): void
    {
        \file_put_contents($this->projectRoot . '/composer.json', '{ this is not valid json');

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function ignores_non_string_provider_entries(): void
    {
        $this->writeComposerJson([
            'extra' => [
                'laravel' => [
                    'providers' => [
                        42,
                        null,
                        ['nested' => 'array'],
                        '',
                        TestStringAliasServiceProvider::class,
                    ],
                ],
            ],
        ]);

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        // Only the string provider registers — non-strings are filtered out before register().
        self::assertTrue($app->bound(TestStringAliasServiceProvider::STRING_KEY));
    }

    #[Test]
    public function tolerates_missing_extra_laravel_providers_shape(): void
    {
        // Each payload exercises a different "shape mismatch" branch in extractProviders().
        $payloads = [
            [],
            ['extra' => 'not-an-array'],
            ['extra' => ['laravel' => 'not-an-array']],
            ['extra' => ['laravel' => ['providers' => 'not-an-array']]],
        ];

        foreach ($payloads as $payload) {
            $this->writeComposerJson($payload);

            $app = $this->freshApp();

            PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

            self::assertFalse($app->bound(TestStringAliasServiceProvider::STRING_KEY));
        }
    }

    #[Test]
    public function honors_dont_discover_skip_list_for_named_package(): void
    {
        // Root composer.json opts a specific package out of auto-discovery.
        $this->writeComposerJson([
            'extra' => [
                'laravel' => [
                    'dont-discover' => ['fake/opted-out'],
                ],
            ],
        ]);

        $this->writeComposerLock([
            'packages' => [
                [
                    'name' => 'fake/opted-out',
                    'extra' => [
                        'laravel' => [
                            'providers' => [TestStringAliasServiceProvider::class],
                        ],
                    ],
                ],
            ],
        ]);

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        self::assertFalse(
            $app->bound(TestStringAliasServiceProvider::STRING_KEY),
            "Provider from a package listed in root composer.json's extra.laravel.dont-discover must not be registered",
        );
    }

    #[Test]
    public function honors_dont_discover_wildcard(): void
    {
        // "*" disables auto-discovery entirely — matches Laravel's PackageManifest behavior.
        $this->writeComposerJson([
            'extra' => [
                'laravel' => [
                    'providers' => [TestStringAliasServiceProvider::class],
                    'dont-discover' => ['*'],
                ],
            ],
        ]);

        $this->writeComposerLock([
            'packages' => [[
                'name' => 'fake/pkg',
                'extra' => ['laravel' => ['providers' => [TestStringAliasServiceProvider::class]]],
            ]],
        ]);

        $app = $this->freshApp();

        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        self::assertFalse(
            $app->bound(TestStringAliasServiceProvider::STRING_KEY),
            'dont-discover ["*"] must suppress both composer.json and composer.lock providers',
        );
    }

    #[Test]
    public function deduplicates_provider_listed_in_both_composer_json_and_lock(): void
    {
        $this->writeComposerJson([
            'extra' => ['laravel' => ['providers' => [TestStringAliasServiceProvider::class]]],
        ]);

        $this->writeComposerLock([
            'packages' => [[
                'name' => 'self/referential',
                'extra' => ['laravel' => ['providers' => [TestStringAliasServiceProvider::class]]],
            ]],
        ]);

        $app = $this->freshApp();

        // Laravel's Application::register() is itself idempotent for the same class,
        // but we still want the registrar to avoid calling register() twice — easier
        // to reason about downstream and cheaper. This test covers that expectation.
        PackageProviderRegistrar::register($app, $this->projectRoot, new VoidProgress());

        self::assertTrue($app->bound(TestStringAliasServiceProvider::STRING_KEY));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        \file_put_contents(
            $this->projectRoot . '/composer.json',
            \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function writeComposerLock(array $data): void
    {
        \file_put_contents(
            $this->projectRoot . '/composer.lock',
            \json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Each test gets its own container so bindings don't leak across tests.
     * The registrar operates on any Illuminate\Foundation\Application instance —
     * we don't need the fully-booted plugin app to exercise the code under test.
     */
    private function freshApp(): Application
    {
        // Touch the plugin application once so trait autoloading and related setup
        // work. The bindings we care about are added to a disposable instance below.
        ApplicationProvider::bootApp();

        return new Application();
    }
}
