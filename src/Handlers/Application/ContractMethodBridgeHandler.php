<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Application;

use Illuminate\Contracts\Container\Container;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;

/**
 * Bridges a Laravel service's public concrete-only methods onto the
 * `Illuminate\Contracts\*` interface it implements, so a concrete-only call on a
 * contract-typed receiver stops raising `UndefinedInterfaceMethod` (psalm 181).
 *
 * Motivating case (#1108): `Command::$laravel` / `ServiceProvider::$app` / `app()`
 * are typed on `Contracts\Foundation\Application`, but `isProduction()`,
 * `isLocal()`, `environmentPath()`, ... live only on the concrete app.
 *
 * Mechanism:
 * - Copy the concrete's `MethodIdentifier`s into the contract's
 *   `declaring_method_ids` + `appearing_method_ids`. `methodExists()` reads those
 *   directly → calls resolve and return/param lookups follow to real storage
 *   (version-correct, no restated signatures).
 * - Never `$contract->methods`: the compliance check iterates it at analysis time
 *   → would force implementors to declare bridged methods (`UnimplementedInterfaceMethod`).
 * - Skip magic + non-public methods (see {@see self::bridgeConcreteMethods()}).
 *
 * Scope — allow-list, not a blind contract walk. Sound only for a contract with a
 * fixed framework-owned implementation the app never swaps (container can only
 * return that one concrete). `Foundation\Application` qualifies. Excluded:
 * - `Queue\Queue`, `Filesystem\Filesystem`: resolved class varies by driver.
 * - `Cache\Repository`: single concrete, but an extension point (config swaps the
 *   inner `Store`; apps bind custom repositories) — extra surface isn't contract.
 * Bridging any would hide real bugs. {@see self::CONTRACT_CONCRETES}.
 *
 * Alternatives rejected:
 * - `MethodExistenceProviderInterface`: only suppresses 181 when the receiver has
 *   `__call` (magic-method path is `__call`-gated); the contract has none.
 * - Contract `.phpstub`: restates every signature (drifts) and re-declaring the
 *   interface resets its parent list unless `extends` is copied verbatim.
 *
 * Hook `AfterCodebasePopulated`: `declaring_method_ids` is rebuilt and the
 * concrete's storage populated by then. Deliberately general counterpart to the
 * hand-listed #1130; maintainer picks either.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1108
 */
final class ContractMethodBridgeHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Contract => sole framework concrete. Add only contracts with a fixed,
     * framework-owned implementation the app never swaps (see class docblock).
     *
     * KEY must be an interface/abstract: the gate relies on `make()` throwing for
     * an unfulfilled contract, but the container auto-builds an instantiable
     * concrete even when unbound, making the gate vacuous.
     *
     * @var array<class-string, class-string>
     */
    private const CONTRACT_CONCRETES = [
        \Illuminate\Contracts\Foundation\Application::class => \Illuminate\Foundation\Application::class,
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $container = ApplicationProvider::getApp();

        foreach (self::CONTRACT_CONCRETES as $contractFqcn => $concreteFqcn) {
            $contract = self::getClassLikeStorage($codebase, $contractFqcn);
            $concrete = self::getClassLikeStorage($codebase, $concreteFqcn);

            if (!$contract instanceof \Psalm\Storage\ClassLikeStorage || !$concrete instanceof \Psalm\Storage\ClassLikeStorage) {
                continue;
            }

            // Widen only a contract the running container fulfils with the mapped
            // concrete. Skips an app that rebinds it; also the unit-test seam.
            if (!self::containerResolvesConcrete($container, $contractFqcn, $concreteFqcn)) {
                continue;
            }

            self::bridgeConcreteMethods($codebase, $contract, $concrete);
        }
    }

    /**
     * True if `$container->make($contract)` resolves to a `$concrete` instance.
     * Catches every Throwable (unbound / partial boot / provider error) and treats
     * a non-object resolution as unfulfilled → don't widen.
     *
     * @param class-string $concrete
     *
     * @internal seam for unit-testing the resolution gate
     */
    public static function containerResolvesConcrete(Container $container, string $contract, string $concrete): bool
    {
        try {
            return self::isInstanceOf($container->make($contract), $concrete);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `make()` is untyped: routing its result through a `mixed` param keeps
     * type-coverage at 100% (params aren't "mixed expressions") and avoids a
     * `RedundantCondition` if a stub narrows the return.
     *
     * @param class-string $concrete
     *
     * @psalm-pure
     */
    private static function isInstanceOf(mixed $value, string $concrete): bool
    {
        return $value instanceof $concrete;
    }

    private static function bridgeConcreteMethods(
        Codebase $codebase,
        ClassLikeStorage $contract,
        ClassLikeStorage $concrete,
    ): void {
        foreach ($concrete->declaring_method_ids as $methodName => $concreteMethodId) {
            // Never bridge magic methods: copying __call would re-enable the
            // magic-method path and silently swallow UndefinedInterfaceMethod
            // (Foundation\Application has __call via Macroable, __get/__set via Container).
            if (\str_starts_with($methodName, '__')) {
                continue;
            }

            // Don't shadow a method already on the contract (own/inherited/prior fire).
            if (isset($contract->declaring_method_ids[$methodName])) {
                continue;
            }

            // Public surface only: bridging a protected/private method turns a clear
            // UndefinedInterfaceMethod into a misleading InaccessibleMethod.
            $methodStorage = self::getMethodStorage($codebase, $concreteMethodId);
            if (!$methodStorage instanceof \Psalm\Storage\MethodStorage || $methodStorage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                continue;
            }

            // Point resolution maps at the concrete's ids → calls resolve and
            // return/param lookups hit real storage.
            $contract->declaring_method_ids[$methodName] = $concreteMethodId;
            $contract->appearing_method_ids[$methodName] = $concrete->appearing_method_ids[$methodName] ?? $concreteMethodId;
        }
    }

    /**
     * @param class-string $fqcn
     *
     * @psalm-mutation-free
     */
    private static function getClassLikeStorage(Codebase $codebase, string $fqcn): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get(\strtolower($fqcn));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Mirrors {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler::getMethodStorage()}
     * — same getStorage() throw contract (InvalidArgumentException: unknown class;
     * UnexpectedValueException: missing method). Keep the catch set in sync.
     *
     * @psalm-mutation-free
     */
    private static function getMethodStorage(Codebase $codebase, MethodIdentifier $methodId): ?MethodStorage
    {
        try {
            return $codebase->methods->getStorage($methodId);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }
    }
}
