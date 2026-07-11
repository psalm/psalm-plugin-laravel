<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Application;

use Illuminate\Contracts\Container\Container;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
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
 * Follow-up (#1230): `Cache::driver()->flexible(...)` — `CacheManager::driver()`
 * returns `Contracts\Cache\Repository`, but `flexible()` lives only on the
 * concrete `Illuminate\Cache\Repository`. Same shape, different contract.
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
 * Scope — dynamic walk over every scanned `Illuminate\Contracts\*` interface
 * (except {@see self::EXCLUDED_CONTRACTS}), bridged to whatever the booted
 * container actually resolves for it. Mechanism parity with Larastan's
 * `ContractsMethodsExtension` (same `make()` + `Throwable`-guard resolution), not
 * trigger parity: Larastan resolves a contract LAZILY, only when a method lookup
 * on it actually misses; this walk resolves EVERY scanned contract EAGERLY at
 * population time. Eager is acceptable here — Laravel service construction is
 * lazy about I/O regardless (e.g. a Redis-backed store's constructor stores the
 * connection manager; the TCP handshake happens on first cache operation, not
 * construction), so eager resolution costs object construction only, and any
 * contract whose `make()` throws (missing service in CI, unbound abstract) is
 * silently skipped exactly as a lazy resolution would be.
 *
 * This supersedes the plugin's earlier allow-list, which listed only
 * `Foundation\Application` and excluded everything else on principle. That
 * caused the #1230 class of false positives on every OTHER contract with a
 * single, driver-invariant concrete (e.g. `Cache\Repository`: every cache driver
 * constructs `Illuminate\Cache\Repository` — only the wrapped `Store` differs).
 *
 * Trade-off, accepted, for a contract like `Cache\Repository` where the concrete
 * CLASS is invariant across drivers: the bridge reflects the DEFAULT binding's
 * concrete (the container resolves the contract once, at population time, to
 * whatever driver is configured as default). A call against a NON-default driver
 * (`Cache::driver('redis')` when the default is `file`) can surface methods the
 * default driver's concrete happens to have but the other driver's concrete
 * lacks, or vice versa. Accepted because it matches Larastan's own behavior for
 * these contracts, the default binding is the dominant runtime shape for most
 * apps, and an app that rebinds a contract to a custom implementation bridges to
 * ITS concrete (resolved per-app, not a fixed mapping), so custom implementations
 * are represented truthfully instead of assumed away. See
 * {@see self::resolveConcreteClass()}.
 *
 * Excluded, by contrast, when the concrete CLASS itself (not just its wrapped
 * dependency) varies by driver — see {@see self::EXCLUDED_CONTRACTS}.
 *
 * Alternatives rejected:
 * - `MethodExistenceProviderInterface`: only suppresses 181 when the receiver has
 *   `__call` (magic-method path is `__call`-gated); the contract has none.
 * - Contract `.phpstub`: restates every signature (drifts) and re-declaring the
 *   interface resets its parent list unless `extends` is copied verbatim.
 *
 * Hook `AfterCodebasePopulated`: `declaring_method_ids` is rebuilt and every
 * scanned concrete's storage populated by then.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1108
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1230
 */
final class ContractMethodBridgeHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Namespace gate: only `Illuminate\Contracts\*` interfaces are eligible.
     * Framework contracts have a container-resolvable abstract by convention
     * (`registerCoreContainerAliases()`); an arbitrary user-land interface does
     * not, and walking every interface in the codebase would call `make()`
     * against class-strings that were never meant to be container abstracts.
     */
    private const CONTRACT_NAMESPACE_PREFIX = 'Illuminate\\Contracts\\';

    /**
     * Contracts excluded from the dynamic walk because the resolved concrete
     * CLASS itself — not just a wrapped dependency — varies by configured
     * driver, so bridging the default driver's surface lies at the
     * class-hierarchy level for every other driver:
     *
     * - `Filesystem\Filesystem` / `Filesystem\Cloud`: `local`/`ftp`/`sftp`
     *   resolve `FilesystemAdapter`, `s3` resolves `AwsS3V3Adapter extends
     *   FilesystemAdapter` with driver-only publics (`url()`,
     *   `temporaryUrl()`, `temporaryUploadUrl()`, `getClient()`). Bridging
     *   whichever disk is configured as default would expose those on every
     *   OTHER disk's contract-typed receiver, or hide them if the default
     *   disk lacks them. {@see \Psalm\LaravelPlugin\Handlers\Filesystem\StorageHandler}
     *   already encodes this exact policy for `disk()`/`drive()` — narrowing
     *   only the return of those two methods, never the contract itself.
     * - `Queue\Queue`: `sync`/`database`/`redis`/`sqs` resolve
     *   `SyncQueue`/`DatabaseQueue`/`RedisQueue`/`SqsQueue` respectively, each
     *   with driver-specific publics; same class-hierarchy mismatch.
     *
     * `Cache\Repository` is NOT here: every cache driver constructs the SAME
     * `Illuminate\Cache\Repository` class (only the wrapped `Store` differs),
     * so bridging it is sound — see the class docblock's accepted trade-off.
     *
     * @var array<class-string, true>
     */
    private const EXCLUDED_CONTRACTS = [
        \Illuminate\Contracts\Filesystem\Filesystem::class => true,
        \Illuminate\Contracts\Filesystem\Cloud::class => true,
        \Illuminate\Contracts\Queue\Queue::class => true,
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $container = ApplicationProvider::getApp();

        foreach (ClassLikeStorageProvider::getAll() as $contract) {
            if (!$contract->is_interface || !\str_starts_with($contract->name, self::CONTRACT_NAMESPACE_PREFIX)) {
                continue;
            }

            if (isset(self::EXCLUDED_CONTRACTS[$contract->name])) {
                continue;
            }

            // ClassLikeStorage::$name is declared `string`, not `class-string` — Psalm
            // has no way to know a scanned classlike's own name is well-formed. It is:
            // the populator only creates storage for classes it scanned or reflected.
            /** @var class-string $contractFqcn */
            $contractFqcn = $contract->name;
            $concreteFqcn = self::resolveConcreteClass($container, $contractFqcn);

            if ($concreteFqcn === null) {
                continue;
            }

            // The container resolved a concrete Psalm never scanned (an
            // unreferenced vendor class) — nothing to bridge from. Don't queue a
            // scan post-population: AfterCodebasePopulated runs after scanning
            // finishes, so a newly queued class wouldn't gain full storage this run.
            $concrete = self::getClassLikeStorage($codebase, $concreteFqcn);

            if (!$concrete instanceof ClassLikeStorage) {
                continue;
            }

            self::bridgeConcreteMethods($codebase, $contract, $concrete);
        }
    }

    /**
     * The class-string of whatever `$container->make($contract)` resolves to, or
     * null if resolution throws (unbound abstract / partial boot / provider
     * error) or yields a non-object. This bridges to the app's ACTUAL binding —
     * an app that rebinds a contract gets its own concrete, not a fixed mapping.
     *
     * @param class-string $contract
     *
     * @return class-string|null
     *
     * @internal seam for unit-testing the resolution gate
     */
    public static function resolveConcreteClass(Container $container, string $contract): ?string
    {
        try {
            return self::classStringOf($container->make($contract));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * `make()` is untyped: routing its result through a `mixed` param keeps
     * type-coverage at 100% (params aren't "mixed expressions") and avoids a
     * `RedundantConditionGivenDocblockType` if a stub narrows the return.
     *
     * @return class-string|null
     *
     * @psalm-pure
     */
    private static function classStringOf(mixed $value): ?string
    {
        return \is_object($value) ? $value::class : null;
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
