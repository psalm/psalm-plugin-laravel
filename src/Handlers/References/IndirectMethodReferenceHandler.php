<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\References;

use Illuminate\Bus\Queueable as BusQueueable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Bus\Dispatchable as BusDispatchable;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Routing\Controller;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Records narrowly-proven Laravel calls that Psalm cannot observe syntactically.
 *
 * Laravel resolves concrete controllers and commands through the container. Their public
 * controller actions, invokable methods, and command handles therefore provide evidence for
 * single, concrete class-typed method parameters; the owning class is also constructed by the
 * container. Eloquent's metadata parser provides a separate proof for relationship methods,
 * which Laravel dispatches through property access and eager-loading magic.
 *
 * The codebase event queues edges after ModelRegistrationHandler has warmed the model metadata.
 * The file-analysis event replays them after Psalm has invalidated incremental references but before
 * dead-code consolidation. It never boots or queries Laravel's container, and only writes through
 * Codebase's supported reference API ({@see Codebase::addReferenceToFunctionLike()}).
 *
 * Controller and Command discovery is limited to Illuminate's base classes: an arbitrary
 * non-Illuminate route class is not treated as dispatched without a statically proven route
 * registration, because doing so would broadly hide genuine dead-code findings. Independently of
 * the base class, {@see self::RULES} roots a concrete class by the contract Laravel calls it
 * through, as a class-conditional edge (alive class => entry method), so an unreferenced class
 * stays dead.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1419
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1779
 * @internal
 */
final class IndirectMethodReferenceHandler implements AfterCodebasePopulatedInterface, AfterFileAnalysisInterface
{
    /**
     * Convention rules, applied in order for every class whose predicate holds (see
     * queueConventionReferences()). `entries`: public non-static method => [Laravel uses its return
     * value, Laravel injects its parameters]. `hooks`/`properties`: what the queue reads off the
     * object itself. `container`: the container builds the class, so its constructor and autowired
     * dependencies are alive. Each name is verified against vendor: Pipeline::carry(),
     * CallQueuedHandler, Queue::createObjectPayload(), Bus\UniqueLock/DebounceLock,
     * Events\Dispatcher, SqsQueue and ReadsClassAttributes::getAttributeValue().
     */
    private const RULES = [
        'invokable' => ['entries' => ['__invoke' => [true, true]], 'container' => true],
        'pipe' => ['entries' => ['handle' => [true, false], 'terminate' => [false, false]], 'container' => true],
        'queued' => [
            'entries' => ['handle' => [true, false], 'failed' => [false, false]],
            'hooks' => [
                'middleware', 'backoff', 'retryUntil', 'tries', 'displayName', 'viaConnection', 'viaQueue',
                'shouldQueue', 'debounceId', 'debounceVia', 'deduplicationId', 'messageGroup',
            ],
            'properties' => [
                'tries', 'maxExceptions', 'timeout', 'failOnTimeout', 'backoff', 'deleteWhenMissingModels',
                'shouldBeEncrypted',
            ],
        ],
        // Laravel reads these only for ShouldBeUnique (PendingDispatch, Events\Dispatcher, Queue, CallQueuedHandler).
        'unique' => ['hooks' => ['uniqueId', 'uniqueVia', 'uniqueFor'], 'properties' => ['uniqueFor']],
        // Only a bus job goes through `Dispatcher::dispatchNow()`'s `Container::call()`; a queued
        // listener gets its event passed positionally by CallQueuedListener.
        'busJob' => ['entries' => ['handle' => [true, true]]],
        'dispatchable' => ['entries' => ['__construct' => [false, false]]],
    ];

    /** @var array<string, array{calling: MethodIdentifier, target: MethodIdentifier}> */
    private static array $methodReferences = [];

    /** @var array<string, MethodIdentifier> */
    private static array $fileReferences = [];

    /** @var array<string, array{class: string, target: MethodIdentifier|array{0: string, 1: string}, returnUsed: bool}> */
    private static array $classReferences = [];

    private static bool $recorded = false;

    public static function reset(): void
    {
        self::$methodReferences = [];
        self::$fileReferences = [];
        self::$classReferences = [];
        self::$recorded = false;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        self::reset();
        $codebase = $event->getCodebase();
        if ($codebase->find_unused_code === null) {
            return;
        }

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (!$storage->user_defined || $storage->abstract || $storage->is_interface || $storage->is_trait) {
                continue;
            }

            $framework = self::entrypointFramework($storage);
            if ($framework !== null) {
                self::recordContainerReferences($codebase, $storage, $framework);
            }

            self::queueConventionReferences($codebase, $storage);
        }

        self::queueRelationReferences($codebase);
    }

    /**
     * Replays queued references after Psalm has invalidated changed methods. The event is emitted
     * once for every analyzed file, but the queue is drained only once per process. In a forked
     * analysis worker this hook runs after the worker's reference-provider reset and its result is
     * merged by Psalm's normal worker consolidation.
     */
    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        if ($codebase->find_unused_code === null) {
            return;
        }

        if (self::$recorded) {
            return;
        }

        self::$recorded = true;

        foreach (self::$methodReferences as $reference) {
            IndirectMethodReferenceRecorder::record(
                $codebase,
                $reference['calling'],
                $reference['target'],
            );
        }

        foreach (self::$classReferences as $reference) {
            IndirectMethodReferenceRecorder::recordClassReference(
                $codebase,
                $reference['class'],
                $reference['target'],
                $reference['returnUsed'],
            );
        }

        foreach (self::$fileReferences as $methodId) {
            IndirectMethodReferenceRecorder::recordFileReference($codebase, $methodId);
        }
    }

    /**
     * @return 'controller'|'command'|null
     * @psalm-mutation-free
     */
    private static function entrypointFramework(ClassLikeStorage $storage): ?string
    {
        $parents = $storage->parent_classes;
        if (isset($parents[\strtolower(Controller::class)])) {
            return 'controller';
        }

        if (isset($parents[\strtolower(Command::class)])) {
            return 'command';
        }

        return null;
    }

    /**
     * @param 'controller'|'command' $framework
     */
    private static function recordContainerReferences(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $framework,
    ): void {
        $entrypoint = null;
        foreach (self::declaredAndInheritedMethods($codebase, $storage) as $method) {
            // Do not treat framework plumbing (callAction/middleware, etc.) as route actions;
            // application-declared inherited and trait methods remain eligible below.
            if ($framework === 'controller'
                && \strtolower($method['declaring']->fq_class_name) === \strtolower(Controller::class)
            ) {
                continue;
            }

            if (!self::isEntrypointMethod($method['name'], $method['storage'], $framework, $method['visibility'])) {
                continue;
            }

            $entrypoint = $method['appearing'];
            // Psalm consolidates calls against the declaring ID (a trait method keeps its
            // trait declaration even though its appearing ID is the consuming class).
            self::$fileReferences[strtolower((string) $method['declaring'])] = $method['declaring'];

            self::queueInjectedConstructors($codebase, $entrypoint, $method['storage']);
        }

        // A concrete class is constructed only as part of a discoverable entrypoint. Use that
        // actual method as the synthetic caller, so this edge remains in Psalm's method graph.
        $constructor = self::publicMethod($codebase, $storage, '__construct');
        if ($constructor instanceof \Psalm\Internal\MethodIdentifier && $entrypoint !== null) {
            self::queueConstructorReference($codebase, $entrypoint, $constructor);
        }
    }

    /**
     * Laravel calls these methods by convention, so Psalm sees no caller. Each edge is sourced at the
     * class, never at an entry method: a method-sourced edge would need the (equally unseen) entry
     * method to be alive first, and a class-sourced one still lets an unreferenced class stay dead.
     */
    private static function queueConventionReferences(Codebase $codebase, ClassLikeStorage $storage): void
    {
        $methods = [];
        foreach (self::declaredAndInheritedMethods($codebase, $storage) as $method) {
            // Only the constructor is kept at any visibility: `Dispatchable::dispatch()` runs `new static()`
            // in the job's own scope. Every other entry point must be public.
            if (($method['visibility'] === ClassLikeAnalyzer::VISIBILITY_PUBLIC || $method['name'] === '__construct')
                && !$method['storage']->is_static
            ) {
                $methods[$method['name']] = $method;
            }
        }

        $traits = $storage->used_traits;
        foreach ($storage->parent_classes as $parent) {
            $traits += ClassLineage::storage($codebase, $parent)->used_traits ?? [];
        }

        // `Foundation\Queue\Queueable` (the `make:job` scaffold) composes Bus `Dispatchable` and `Queueable`.
        $dispatchable = isset($traits[\strtolower(BusDispatchable::class)])
            || isset($traits[\strtolower(FoundationQueueable::class)]);
        $active = [
            'invokable' => isset($methods['__invoke']),
            // A native `Closure` on `$next` is all that tells a pipe from any other class with a
            // public handle(); a docblock does not count.
            'pipe' => isset($methods['handle']) && self::isNativeClosure($methods['handle']['storage']->params[1] ?? null),
            'dispatchable' => $dispatchable,
            'busJob' => $dispatchable || isset($traits[\strtolower(BusQueueable::class)]),
        ];
        $active['queued'] = $active['busJob'] || ClassLineage::isA($codebase, $storage->name, ShouldQueue::class);
        $active['unique'] = $active['queued'] && ClassLineage::isA($codebase, $storage->name, ShouldBeUnique::class);

        foreach (self::RULES as $rule => $config) {
            if (!($active[$rule] ?? false)) {
                continue;
            }

            $entries = $config['entries'] ?? [];
            foreach ($config['hooks'] ?? [] as $hook) {
                $entries[\strtolower($hook)] = [true, false];
            }

            foreach ($entries as $name => [$returnUsed, $injected]) {
                if (isset($methods[$name])) {
                    self::queueClassReference($storage->name, $methods[$name]['declaring'], $returnUsed);
                    if ($injected) {
                        self::queueInjectedConstructors($codebase, $methods[$name]['declaring'], $methods[$name]['storage']);
                    }
                }
            }

            // Read from outside the class (`isset($job->tries)`), so public non-static only. The edge
            // targets the declaring class's node, the one Psalm checks for unused properties.
            foreach ($config['properties'] ?? [] as $name) {
                $declaring = ClassLineage::storage($codebase, $storage->declaring_property_ids[$name] ?? '');
                $property = $declaring?->properties[$name] ?? null;
                if ($declaring instanceof \Psalm\Storage\ClassLikeStorage && $property !== null
                    && $property->visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC && !$property->is_static
                ) {
                    self::queueClassReference($storage->name, [$declaring->name, $name], false);
                }
            }

            // The container needs a public constructor; `dispatch()`'s `new static()` does not (see RULES).
            if (($config['container'] ?? false) && ($methods['__construct']['visibility'] ?? null) === ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
                self::queueConstructorReference($codebase, $storage->name, $methods['__construct']['declaring']);
            }
        }
    }

    private static function queueInjectedConstructors(
        Codebase $codebase,
        MethodIdentifier $calling,
        MethodStorage $method,
    ): void {
        foreach ($method->params as $parameter) {
            $constructor = self::injectedConstructor($codebase, $parameter);
            if ($constructor instanceof MethodIdentifier) {
                self::queueConstructorReference($codebase, $calling, $constructor);
            }
        }
    }

    /** @param MethodIdentifier|array{0: string, 1: string} $target a method, or [declaring class, property] */
    private static function queueClassReference(string $className, MethodIdentifier|array $target, bool $returnUsed): void
    {
        $key = $target instanceof MethodIdentifier ? strtolower((string) $target) : \implode('::$', $target);
        self::$classReferences[strtolower($className) . '>' . $key] = [
            'class' => $className,
            'target' => $target,
            'returnUsed' => $returnUsed,
        ];
    }

    /** @psalm-mutation-free */
    private static function isNativeClosure(?FunctionLikeParameter $parameter): bool
    {
        // A native `?Closure` is Closure|null; Laravel passes a Closure either way.
        $atomics = \array_filter(
            $parameter?->signature_type?->getAtomicTypes() ?? [],
            static fn(\Psalm\Type\Atomic $atomic): bool => !$atomic instanceof TNull,
        );
        $atomic = \reset($atomics);

        return \count($atomics) === 1 && $atomic instanceof TNamedObject && \strtolower($atomic->value) === 'closure';
    }

    /**
     * Controller public methods are route-action candidates because Laravel routes are configured
     * outside Psalm's type graph. Command methods are intentionally restricted to handle(); this
     * keeps public command helpers from becoming false evidence.
     *
     * @param 'controller'|'command' $framework
     * @psalm-mutation-free
     */
    private static function isEntrypointMethod(
        string $methodName,
        MethodStorage $method,
        string $framework,
        int $visibility,
    ): bool {
        if ($visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC || $method->is_static) {
            return false;
        }

        $methodName = \strtolower($methodName);

        return $framework === 'command'
            ? $methodName === 'handle'
            : $methodName === '__invoke' || $methodName !== '__construct';
    }

    /**
     * Resolves the constructor of the single concrete class a constructor-injectable parameter is
     * typed to, using Laravel's own reflection rules: it sees the native signature, not a
     * Psalm-only docblock type, so nullable, variadic, union, and intersection parameters are
     * deliberately ambiguous.
     *
     * @psalm-mutation-free
     */
    private static function injectedConstructor(Codebase $codebase, FunctionLikeParameter $parameter): ?MethodIdentifier
    {
        if ($parameter->is_nullable || $parameter->is_variadic) {
            return null;
        }

        $type = $parameter->signature_type;
        if (!$type instanceof Union || \count($type->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = \array_values($type->getAtomicTypes())[0];
        if (!$atomic instanceof TNamedObject) {
            return null;
        }

        try {
            $target = $codebase->classlike_storage_provider->get($atomic->value);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if ($target->is_interface || $target->abstract) {
            return null;
        }

        return self::publicMethod($codebase, $target, '__construct');
    }

    /** @psalm-mutation-free */
    private static function publicMethod(
        Codebase $codebase,
        ClassLikeStorage $storage,
        string $methodName,
    ): ?MethodIdentifier {
        $methodId = $storage->declaring_method_ids[\strtolower($methodName)] ?? null;
        if (!$methodId instanceof MethodIdentifier) {
            return null;
        }

        try {
            $declaringStorage = $codebase->classlike_storage_provider->get($methodId->fq_class_name);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $method = $declaringStorage->methods[$methodId->method_name] ?? null;
        if (!$method instanceof MethodStorage || $method->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
            return null;
        }

        return $methodId;
    }

    private static function queueRelationReferences(Codebase $codebase): void
    {
        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (!$storage->user_defined
                || !isset($storage->parent_classes[\strtolower(Model::class)])
            ) {
                continue;
            }

            foreach (self::declaredAndInheritedMethods($codebase, $storage) as $method) {
                if ($method['visibility'] !== ClassLikeAnalyzer::VISIBILITY_PUBLIC
                    || !self::isRelationMethod($codebase, $method['declaring'], $method['storage'])
                ) {
                    continue;
                }

                // Relation dispatch is property/magic based, so there is no real calling method.
                // A file-member edge is the supported Psalm representation and avoids a bogus
                // relation::relation self-edge. It is replayed after incremental invalidation.
                self::$fileReferences[strtolower((string) $method['declaring'])] = $method['declaring'];
            }
        }
    }

    private static function queueMethodReference(MethodIdentifier $calling, MethodIdentifier $target): void
    {
        self::$methodReferences[strtolower((string) $calling) . '>' . strtolower((string) $target)] = [
            'calling' => $calling,
            'target' => $target,
        ];
    }

    /**
     * Queue a container construction and the native-signature dependencies of its constructor.
     * Laravel resolves constructor dependencies recursively, so stopping at the first edge would
     * leave valid nested autowired constructors reported as dead.
     *
     * @param array<string, bool> $seen
     */
    private static function queueConstructorReference(
        Codebase $codebase,
        MethodIdentifier|string $calling,
        MethodIdentifier $target,
        array &$seen = [],
    ): void {
        $targetKey = strtolower((string) $target);
        if (isset($seen[$targetKey])) {
            return;
        }

        $seen[$targetKey] = true;
        if ($calling instanceof MethodIdentifier) {
            self::queueMethodReference($calling, $target);
        } else {
            self::queueClassReference($calling, $target, false);
        }

        try {
            $classStorage = $codebase->classlike_storage_provider->get($target->fq_class_name);
        } catch (\InvalidArgumentException) {
            return;
        }

        $constructorStorage = $classStorage->methods[$target->method_name] ?? null;
        if (!$constructorStorage instanceof MethodStorage) {
            return;
        }

        foreach ($constructorStorage->params as $parameter) {
            $dependency = self::injectedConstructor($codebase, $parameter);
            if ($dependency instanceof \Psalm\Internal\MethodIdentifier) {
                self::queueConstructorReference($codebase, $target, $dependency, $seen);
            }
        }
    }

    /**
     * @return iterable<array{
     *     name: string,
     *     appearing: MethodIdentifier,
     *     declaring: MethodIdentifier,
     *     storage: MethodStorage,
     *     visibility: int,
     * }>
     * @psalm-mutation-free
     */
    private static function declaredAndInheritedMethods(Codebase $codebase, ClassLikeStorage $storage): iterable
    {
        foreach ($storage->appearing_method_ids as $name => $appearing) {
            $declaring = $storage->declaring_method_ids[$name] ?? null;
            if (!$declaring instanceof MethodIdentifier) {
                continue;
            }

            try {
                $declaringStorage = $codebase->classlike_storage_provider->get($declaring->fq_class_name);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $method = $declaringStorage->methods[$declaring->method_name] ?? null;
            if (!$method instanceof MethodStorage) {
                continue;
            }

            // A `use T { f as protected; }` adaptation is stored on the class that uses the trait,
            // which for an inherited method is the appearing class (the parent), not this one.
            $appearingStorage = $appearing->fq_class_name === $storage->name
                ? $storage
                : ClassLineage::storage($codebase, $appearing->fq_class_name);

            yield [
                'name' => $name,
                'appearing' => $appearing,
                'declaring' => $declaring,
                'storage' => $method,
                'visibility' => $appearingStorage?->trait_visibility_map[$name] ?? $method->visibility,
            ];
        }
    }

    private static function isRelationMethod(
        Codebase $codebase,
        MethodIdentifier $declaring,
        MethodStorage $method,
    ): bool {
        $returnType = $method->return_type ?? $method->signature_return_type;
        if ($returnType instanceof Union) {
            foreach ($returnType->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TNamedObject && ClassLineage::isA($codebase, $atomic->value, Relation::class)) {
                    return true;
                }
            }

            if (!$returnType->isMixed()) {
                return false;
            }
        }

        return RelationMethodParser::parse($codebase, $declaring->fq_class_name, $declaring->method_name) !== null;
    }

}
