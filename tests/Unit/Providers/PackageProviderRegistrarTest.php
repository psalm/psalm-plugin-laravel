<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\PackageProviderRegistrar;
use Psalm\Progress\VoidProgress;

#[CoversClass(PackageProviderRegistrar::class)]
final class PackageProviderRegistrarTest extends TestCase
{
    #[Test]
    public function discoverProviders_reads_project_composer_json(): void
    {
        $projectRoot = self::tempDirWithComposerJson([
            'extra' => [
                'laravel' => [
                    'providers' => [
                        'App\\Providers\\TestServiceProvider',
                    ],
                ],
            ],
        ]);

        $providers = PackageProviderRegistrar::discoverProviders($projectRoot);

        self::assertContains('App\\Providers\\TestServiceProvider', $providers);

        self::cleanTempDir($projectRoot);
    }

    #[Test]
    public function discoverProviders_reads_composer_lock_packages(): void
    {
        $projectRoot = self::tempDirWithComposerJsonAndLock(
            [],
            [
                'packages' => [
                    [
                        'name' => 'vendor/some-package',
                        'extra' => [
                            'laravel' => [
                                'providers' => [
                                    'Vendor\\SomePackage\\ServiceProvider',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $providers = PackageProviderRegistrar::discoverProviders($projectRoot);

        self::assertContains('Vendor\\SomePackage\\ServiceProvider', $providers);

        self::cleanTempDir($projectRoot);
    }

    #[Test]
    public function discoverProviders_respects_dont_discover_for_specific_package(): void
    {
        $projectRoot = self::tempDirWithComposerJsonAndLock(
            [
                'extra' => [
                    'laravel' => [
                        'dont-discover' => ['vendor/excluded-package'],
                    ],
                ],
            ],
            [
                'packages' => [
                    [
                        'name' => 'vendor/excluded-package',
                        'extra' => [
                            'laravel' => [
                                'providers' => [
                                    'Vendor\\ExcludedPackage\\ServiceProvider',
                                ],
                            ],
                        ],
                    ],
                    [
                        'name' => 'vendor/included-package',
                        'extra' => [
                            'laravel' => [
                                'providers' => [
                                    'Vendor\\IncludedPackage\\ServiceProvider',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $providers = PackageProviderRegistrar::discoverProviders($projectRoot);

        self::assertNotContains('Vendor\\ExcludedPackage\\ServiceProvider', $providers);
        self::assertContains('Vendor\\IncludedPackage\\ServiceProvider', $providers);

        self::cleanTempDir($projectRoot);
    }

    #[Test]
    public function discoverProviders_respects_dont_discover_wildcard(): void
    {
        $projectRoot = self::tempDirWithComposerJsonAndLock(
            [
                'extra' => [
                    'laravel' => [
                        'providers' => ['App\\Providers\\OwnProvider'],
                        'dont-discover' => ['*'],
                    ],
                ],
            ],
            [
                'packages' => [
                    [
                        'name' => 'vendor/any-package',
                        'extra' => [
                            'laravel' => [
                                'providers' => [
                                    'Vendor\\AnyPackage\\ServiceProvider',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $providers = PackageProviderRegistrar::discoverProviders($projectRoot);

        self::assertContains('App\\Providers\\OwnProvider', $providers);
        self::assertNotContains('Vendor\\AnyPackage\\ServiceProvider', $providers);

        self::cleanTempDir($projectRoot);
    }

    #[Test]
    public function discoverProviders_deduplicates_providers(): void
    {
        $projectRoot = self::tempDirWithComposerJsonAndLock(
            [
                'extra' => [
                    'laravel' => [
                        'providers' => ['Vendor\\SomePackage\\ServiceProvider'],
                    ],
                ],
            ],
            [
                'packages' => [
                    [
                        'name' => 'vendor/some-package',
                        'extra' => [
                            'laravel' => [
                                'providers' => [
                                    'Vendor\\SomePackage\\ServiceProvider',
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $providers = PackageProviderRegistrar::discoverProviders($projectRoot);

        self::assertCount(\count(\array_unique($providers)), $providers, 'Providers should be deduplicated');
        self::assertCount(1, $providers);

        self::cleanTempDir($projectRoot);
    }

    #[Test]
    public function register_survives_missing_provider_class(): void
    {
        ApplicationProvider::bootApp();

        $app = ApplicationProvider::getApp();
        $progress = new VoidProgress();

        $projectRoot = self::tempDirWithComposerJson([
            'extra' => [
                'laravel' => [
                    'providers' => [
                        'This\\Provider\\Does\\Not\\Exist',
                    ],
                ],
            ],
        ]);

        // Should complete without throwing
        PackageProviderRegistrar::register($app, $projectRoot, $progress);
        $this->addToAssertionCount(1);

        self::cleanTempDir($projectRoot);
    }

    #[Test]
    public function register_skips_providers_that_throw_on_registration(): void
    {
        ApplicationProvider::bootApp();

        $app = ApplicationProvider::getApp();
        $progress = new VoidProgress();

        // Create a temporary provider class that throws during register()
        $tmpFile = \sys_get_temp_dir() . '/ThrowingProvider_' . \uniqid() . '.php';
        $className = 'ThrowingProvider_' . \uniqid();
        \file_put_contents($tmpFile, "<?php\nclass {$className} extends \\Illuminate\\Support\\ServiceProvider { public function register(): void { throw new \\RuntimeException('deliberate failure'); } }");
        require $tmpFile;

        $projectRoot = self::tempDirWithComposerJson([
            'extra' => [
                'laravel' => [
                    'providers' => [$className],
                ],
            ],
        ]);

        // Should complete without throwing
        PackageProviderRegistrar::register($app, $projectRoot, $progress);
        $this->addToAssertionCount(1);

        \unlink($tmpFile);
        self::cleanTempDir($projectRoot);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<mixed> $composerJsonData
     * @return non-empty-string
     */
    private static function tempDirWithComposerJson(array $composerJsonData): string
    {
        $dir = \sys_get_temp_dir() . '/psalm_ppr_test_' . \uniqid();
        \mkdir($dir, 0777, true);
        \file_put_contents($dir . '/composer.json', \json_encode($composerJsonData));

        return $dir;
    }

    /**
     * @param array<mixed> $composerJsonData
     * @param array<mixed> $composerLockData
     * @return non-empty-string
     */
    private static function tempDirWithComposerJsonAndLock(array $composerJsonData, array $composerLockData): string
    {
        $dir = self::tempDirWithComposerJson($composerJsonData);
        \file_put_contents($dir . '/composer.lock', \json_encode($composerLockData));

        return $dir;
    }

    private static function cleanTempDir(string $dir): void
    {
        if (\is_dir($dir)) {
            foreach (\glob($dir . '/*') ?: [] as $file) {
                \unlink($file);
            }

            \rmdir($dir);
        }
    }
}
