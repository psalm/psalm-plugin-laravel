<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\CastContractUserDefinedHandler;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Regression for the warm-cache `MethodSignatureMismatch` reported on every class
 * implementing `CastsAttributes` / `CastsInboundAttributes`.
 *
 * The plugin registers framework `.phpstub` files, which Psalm scans with
 * `Codebase::$register_stub_files = true`. During that pass the real cast-contract
 * interfaces are pulled in as transitive dependencies and stamped
 * `user_defined = false` (`ClassLikeNodeScanner`), and that snapshot is cached.
 * On a warm cache `MethodComparator` then treats the docblock
 * `@param array<string, mixed> $attributes` as a signature type and reports a bogus
 * mismatch against an implementer's native `array`. A `--no-cache` run rescans the
 * interfaces as real dependencies (`user_defined = true`) and is clean.
 *
 * The handler restores `user_defined = true` after population so the warm-cache
 * result matches the `--no-cache` result.
 */
#[CoversClass(CastContractUserDefinedHandler::class)]
final class CastContractUserDefinedHandlerTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        ClassLikeStorageProvider::deleteAll();
    }

    #[Test]
    public function it_restores_user_defined_on_cast_contracts_mis_stamped_during_stub_scan(): void
    {
        $provider = new ClassLikeStorageProvider();

        // Reproduce the cached snapshot written while register_stub_files was true.
        $castsAttributes = $provider->create(CastsAttributes::class);
        $castsAttributes->user_defined = false;

        $castsInbound = $provider->create(CastsInboundAttributes::class);
        $castsInbound->user_defined = false;

        // An unrelated framework class the handler must not touch.
        $unrelated = $provider->create(Model::class);
        $unrelated->user_defined = false;

        CastContractUserDefinedHandler::afterCodebasePopulated($this->eventFor($provider));

        $this->assertTrue(
            $castsAttributes->user_defined,
            'CastsAttributes must be restored to user_defined so MethodComparator skips the stub-only signature check.',
        );
        $this->assertTrue(
            $castsInbound->user_defined,
            'CastsInboundAttributes must be restored to user_defined.',
        );
        $this->assertFalse(
            $unrelated->user_defined,
            'Classes outside the cast contracts must be left untouched.',
        );
    }

    private function eventFor(ClassLikeStorageProvider $provider): AfterCodebasePopulatedEvent
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $provider;

        return new AfterCodebasePopulatedEvent($codebase);
    }
}
