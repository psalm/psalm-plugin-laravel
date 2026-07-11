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
 * Bridges concrete-only public methods onto every scanned `Illuminate\Contracts\*`
 * interface (minus {@see self::EXCLUDED_CONTRACTS}), resolved via the booted
 * container, so e.g. `Cache::driver()->flexible(...)` stops raising
 * `UndefinedInterfaceMethod` (#1108, #1230). Larastan's `ContractsMethodsExtension`
 * equivalent, but eager (per scanned contract at population) instead of lazy —
 * safe because Laravel constructors defer I/O and a throwing `make()` just skips.
 *
 * Mechanism:
 * - Copy the concrete's ids into the contract's `declaring_method_ids` +
 *   `appearing_method_ids`; lookups then follow to real storage (version-correct).
 * - Never `$contract->methods`: `UnimplementedInterfaceMethod`'s compliance check
 *   iterates it and would force implementors to declare bridged methods.
 * - Skip magic + non-public methods ({@see self::bridgeConcreteMethods()}).
 *
 * The bridge reflects the app's ACTUAL binding: rebound contracts bridge to their
 * own concrete; the default driver's surface is assumed for multi-driver services
 * (Larastan does the same). Rejected alternatives: `MethodExistenceProviderInterface`
 * (only works on `__call` receivers), contract stubs (restate every signature).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1108
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1230
 */
final class ContractMethodBridgeHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Only framework contracts are container-resolvable by convention
     * (`registerCoreContainerAliases()`); user-land interfaces are not abstracts.
     */
    private const CONTRACT_NAMESPACE_PREFIX = 'Illuminate\\Contracts\\';

    /**
     * Excluded: the resolved concrete CLASS varies by configured driver (`s3` →
     * `AwsS3V3Adapter` with `temporaryUrl()`/`getClient()` other disks lack;
     * queue drivers → `RedisQueue`/`SqsQueue`/...), so bridging the default
     * driver's surface would lie for every other driver. Same policy as
     * {@see \Psalm\LaravelPlugin\Handlers\Filesystem\StorageHandler}. Not
     * `Cache\Repository`: every cache driver constructs the same class.
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

            // ClassLikeStorage::$name is `string`, but scanned storage names are FQCNs.
            /** @var class-string $contractFqcn */
            $contractFqcn = $contract->name;
            $concreteFqcn = self::resolveConcreteClass($container, $contractFqcn);

            if ($concreteFqcn === null) {
                continue;
            }

            // Unscanned concrete → nothing to bridge from (too late to queue a scan).
            $concrete = self::getClassLikeStorage($codebase, $concreteFqcn);

            if (!$concrete instanceof ClassLikeStorage) {
                continue;
            }

            self::bridgeConcreteMethods($codebase, $contract, $concrete);
        }
    }

    /**
     * Class of whatever `make($contract)` resolves to; null on throw or non-object.
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
     * `mixed` param keeps type-coverage at 100% for the untyped `make()` result
     * and avoids `RedundantConditionGivenDocblockType` if a stub narrows it.
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
            // Bridging __call would re-enable the magic-method path and
            // silently swallow UndefinedInterfaceMethod.
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
