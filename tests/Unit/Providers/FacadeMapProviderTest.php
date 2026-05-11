<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Providers;

use Illuminate\Foundation\AliasLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\FacadeMapProvider;
use Psalm\Progress\VoidProgress;

#[CoversClass(FacadeMapProvider::class)]
final class FacadeMapProviderTest extends TestCase
{
    /**
     * Reproduces issue #745: the mateffy/laravel-introspect package publishes
     * a self-referential alias via composer.json:
     *
     *     "extra": { "laravel": { "aliases": { "Introspect": "Introspect" } } }
     *
     * AliasLoader stores this literally, so when anything triggers
     * spl_autoload('Introspect'), AliasLoader::load() calls
     * class_alias('Introspect', 'Introspect') and PHP emits "Class not found".
     * Psalm's error handler promotes that warning to an exception.
     *
     * FacadeMapProvider::init() calls is_subclass_of($facadeClass, Facade::class)
     * on every alias entry, which is what triggers the autoload. Without the
     * catch, one broken package disables the whole plugin for the run.
     *
     * This test registers a broken alias, installs a warnings-as-exceptions
     * handler (mirroring Psalm's behavior), and verifies init() completes.
     */
    #[Test]
    public function init_survives_self_referential_broken_alias(): void
    {
        ApplicationProvider::bootApp();

        $aliasLoader = AliasLoader::getInstance();
        $originalAliases = $aliasLoader->getAliases();

        // Register the same broken pattern published by mateffy/laravel-introspect.
        $aliasLoader->setAliases($originalAliases + ['Introspect_Test745' => 'Introspect_Test745']);

        // Mirror Psalm\Internal\ErrorHandler: promote non-suppressed warnings to exceptions.
        // PHPUnit strips E_WARNING from error_reporting, so bypass that gate here —
        // we want to simulate Psalm's strict handler that throws on every warning.
        $originalReporting = \error_reporting(\E_ALL);
        \set_error_handler(static function (int $errno, string $message): bool {
            if ((\error_reporting() & $errno) === 0) {
                return false;
            }

            throw new \RuntimeException($message);
        });

        try {
            FacadeMapProvider::init(new VoidProgress());
            $this->addToAssertionCount(1); // reached without an uncaught exception
        } finally {
            \restore_error_handler();
            \error_reporting($originalReporting);
            $aliasLoader->setAliases($originalAliases);
        }
    }

    /**
     * Locks in issue #899 idea #4: each Macroable per-store concrete that a
     * non-Macroable manager forwards `__call` to must resolve back to the
     * corresponding facade through `getFacadeClasses()`. Catching a regression
     * here flags a `MULTI_TARGET_FACADES` typo (or a Laravel version reshuffle
     * that broke a hardcoded edge) directly, instead of surfacing as a
     * confusing "macro not visible on facade" cascade through `MacroHandler`.
     *
     * Each row asserts the canonical facade FQCN appears under the concrete's
     * key after `init()`. Global-alias entries (`Auth`, `Cache`, ...) are
     * environment-dependent (AliasLoader state at the time of init), so they
     * are NOT asserted here — the type-level dispatch test
     * `MacroFacadeDispatchTest.phpt` covers them end-to-end under Testbench
     * defaults.
     *
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function multiTargetEdgeProvider(): iterable
    {
        yield 'Auth via SessionGuard' => [
            \Illuminate\Auth\SessionGuard::class,
            \Illuminate\Support\Facades\Auth::class,
        ];
        yield 'Auth via RequestGuard' => [
            \Illuminate\Auth\RequestGuard::class,
            \Illuminate\Support\Facades\Auth::class,
        ];
        yield 'Auth via TokenGuard' => [
            \Illuminate\Auth\TokenGuard::class,
            \Illuminate\Support\Facades\Auth::class,
        ];
        yield 'Cache via Repository' => [
            \Illuminate\Cache\Repository::class,
            \Illuminate\Support\Facades\Cache::class,
        ];
        yield 'Session via Store' => [
            \Illuminate\Session\Store::class,
            \Illuminate\Support\Facades\Session::class,
        ];
        yield 'Storage via FilesystemAdapter' => [
            \Illuminate\Filesystem\FilesystemAdapter::class,
            \Illuminate\Support\Facades\Storage::class,
        ];
        yield 'Mail via Mailer' => [
            \Illuminate\Mail\Mailer::class,
            \Illuminate\Support\Facades\Mail::class,
        ];
    }

    /**
     * @param class-string $concrete
     * @param class-string $expectedFacade
     */
    #[Test]
    #[DataProvider('multiTargetEdgeProvider')]
    public function init_seeds_multi_target_facade_edges(string $concrete, string $expectedFacade): void
    {
        ApplicationProvider::bootApp();
        FacadeMapProvider::init(new VoidProgress());

        $facades = FacadeMapProvider::getFacadeClasses($concrete);

        self::assertContains(
            $expectedFacade,
            $facades,
            "Expected `{$expectedFacade}` to appear in getFacadeClasses({$concrete}); got: "
            . \json_encode($facades),
        );
    }

}
