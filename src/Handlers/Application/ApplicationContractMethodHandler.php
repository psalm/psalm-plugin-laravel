<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Application;

use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;

/**
 * Declares the environment/state methods that exist on the concrete
 * {@see \Illuminate\Foundation\Application} but NOT on its
 * {@see \Illuminate\Contracts\Foundation\Application} contract, so calling them
 * on a contract-typed receiver (`Command::$laravel`, `ServiceProvider::$app`, or
 * any property/parameter typed on the interface) no longer raises
 * `UndefinedInterfaceMethod` (psalm 181).
 *
 * **Why a resolution-map write and not a `.phpstub`.** Stubbing the contract to
 * add these methods would mean restating every signature in the stub (which drifts
 * as Laravel evolves) and re-declaring the interface — which, unless the full
 * `extends` clause is copied verbatim, resets the interface's parent list (see the
 * "Class declarations wipe reflected metadata" note in the contributing docs).
 * Pointing the contract's resolution maps at the live concrete app keeps signatures
 * version-correct and leaves the reflected interface untouched.
 *
 * **Why not a method-existence provider.** `MethodExistenceProviderInterface` only
 * suppresses 181 when the receiver declares `__call` (the analyzer routes a
 * provider-confirmed-but-storage-less method through the magic-method handler,
 * which is `__call`-gated — see `AtomicMethodCallAnalyzer`). The contract has no
 * `__call`, so the method must exist for real in `declaring_method_ids`; only a
 * resolution-map entry achieves that.
 *
 * **Why only the resolution maps, not `$contract->methods`.** Naive
 * `methodExists()` consults `declaring_method_ids` directly, so adding entries
 * there (pointed at the concrete app's own declarations) is enough to resolve the
 * call. Deliberately NOT touching `$contract->methods`: the interface-compliance
 * check iterates `$interface->methods` at analysis time, so writing there would
 * force every class that `implements` the contract to declare these 9 methods
 * (spurious `UnimplementedInterfaceMethod`). Keeping them out of `methods` makes
 * the injection invisible to compliance while still resolving the call.
 *
 * Runs at `AfterCodebasePopulated` (not `AfterClassLikeVisit`) so the write lands
 * after the populator has rebuilt `declaring_method_ids`, and so the concrete
 * app's own storage is already populated to copy ids from. Mirrors
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1108
 */
final class ApplicationContractMethodHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Methods declared only on the concrete `Illuminate\Foundation\Application`,
     * absent from the `Illuminate\Contracts\Foundation\Application` contract.
     * Verified against laravel/framework across the ^12.4 floor and Laravel 13.
     *
     * Resolution is delegated to the concrete app's own declarations rather than
     * restated here, so signatures stay correct as Laravel evolves. If a future
     * Laravel promotes one of these onto the contract, the loop below leaves the
     * real declaration untouched.
     *
     * @var list<lowercase-string>
     */
    private const CONCRETE_ONLY_METHODS = [
        'islocal',
        'isproduction',
        'detectenvironment',
        'environmentfile',
        'environmentfilepath',
        'environmentpath',
        'useenvironmentpath',
        'loadenvironmentfrom',
        'afterloadingenvironment',
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        $contract = self::getStorage($codebase, ApplicationContract::class);
        if (!$contract instanceof \Psalm\Storage\ClassLikeStorage) {
            return;
        }

        // The concrete application bound in the container (normally
        // Illuminate\Foundation\Application; custom app classes are honoured too).
        $concrete = self::getStorage($codebase, ApplicationProvider::getAppFullyQualifiedClassName());
        if (!$concrete instanceof \Psalm\Storage\ClassLikeStorage) {
            return;
        }

        foreach (self::CONCRETE_ONLY_METHODS as $method) {
            // Already resolvable on the contract (a real future declaration, or a
            // prior fire in this process) — never shadow it.
            if (isset($contract->declaring_method_ids[$method])) {
                continue;
            }

            // Where the concrete app declares the method. Reading declaring_method_ids
            // (not ->methods) follows inheritance/traits, so this stays correct if a
            // future Laravel relocates a method onto a parent or trait. Also guards a
            // custom application class that drops one of these methods.
            $concreteMethodId = $concrete->declaring_method_ids[$method] ?? null;
            if ($concreteMethodId === null) {
                continue;
            }

            // Point the contract's method resolution at the concrete implementation.
            // naive methodExists() reads declaring_method_ids directly, so the call
            // resolves on contract-typed receivers, and return/param lookups follow
            // the id to the concrete's real storage (bool / string / $this).
            $contract->declaring_method_ids[$method] = $concreteMethodId;
            $contract->appearing_method_ids[$method] = $concrete->appearing_method_ids[$method] ?? $concreteMethodId;
        }
    }

    /** @psalm-mutation-free */
    private static function getStorage(Codebase $codebase, string $fqcn): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get(\strtolower($fqcn));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
