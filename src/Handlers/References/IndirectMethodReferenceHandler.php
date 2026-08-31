<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\References;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Controller;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterFileAnalysisEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\TNamedObject;
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
 * FileReferenceProvider's supported reference API.
 *
 * Discovery is intentionally limited to Illuminate's Controller and Command base classes. Arbitrary
 * non-Illuminate route classes are not treated as dispatched without a statically proven Laravel
 * route registration, because doing so would broadly hide genuine dead-code findings.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1419
 * @internal
 */
final class IndirectMethodReferenceHandler implements AfterCodebasePopulatedInterface, AfterFileAnalysisInterface
{
    /** @var array<string, array{calling: MethodIdentifier, target: MethodIdentifier}> */
    private static array $methodReferences = [];

    /** @var array<string, MethodIdentifier> */
    private static array $fileReferences = [];

    private static bool $recorded = false;

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$methodReferences = [];
        self::$fileReferences = [];
        self::$recorded = false;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        self::reset();
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (!$storage->user_defined || $storage->abstract || $storage->is_interface) {
                continue;
            }

            $framework = self::entrypointFramework($storage);
            if ($framework !== null) {
                self::recordContainerReferences($codebase, $storage, $framework);
            }
        }

        self::queueRelationReferences($codebase);
    }

    /**
     * Replays queued references after Psalm has invalidated changed methods. The event is emitted
     * once for every analyzed file, but the queue is drained only once per process. In a forked
     * analysis worker this hook runs after the worker's reference-provider reset and its result is
     * merged by Psalm's normal worker consolidation.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function afterAnalyzeFile(AfterFileAnalysisEvent $event): void
    {
        if (self::$recorded) {
            return;
        }

        self::$recorded = true;
        $codebase = $event->getCodebase();

        foreach (self::$methodReferences as $reference) {
            IndirectMethodReferenceRecorder::record(
                $codebase,
                $reference['calling'],
                $reference['target'],
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

            foreach ($method['storage']->params as $parameter) {
                $constructor = self::injectedConstructor($codebase, $parameter);
                if ($constructor instanceof \Psalm\Internal\MethodIdentifier) {
                    self::queueConstructorReference(
                        $codebase,
                        $entrypoint,
                        $constructor,
                    );
                }
            }
        }

        // A concrete class is constructed only as part of a discoverable entrypoint. Use that
        // actual method as the synthetic caller, so this edge remains in Psalm's method graph.
        $constructor = self::publicMethod($codebase, $storage, '__construct');
        if ($constructor instanceof \Psalm\Internal\MethodIdentifier && $entrypoint !== null) {
            self::queueConstructorReference($codebase, $entrypoint, $constructor);
        }
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

    /** @psalm-mutation-free */
    private static function injectedConstructor(Codebase $codebase, FunctionLikeParameter $parameter): ?MethodIdentifier
    {
        // Laravel's reflection sees the native signature, not a Psalm-only docblock type.
        // Nullable, variadic, union, and intersection parameters are deliberately ambiguous.
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

    /** @psalm-external-mutation-free */
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
        MethodIdentifier $calling,
        MethodIdentifier $target,
        array &$seen = [],
    ): void {
        $targetKey = strtolower((string) $target);
        if (isset($seen[$targetKey])) {
            return;
        }

        $seen[$targetKey] = true;
        self::queueMethodReference($calling, $target);

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

            yield [
                'name' => $name,
                'appearing' => $appearing,
                'declaring' => $declaring,
                'storage' => $method,
                'visibility' => $storage->trait_visibility_map[$name] ?? $method->visibility,
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
                if ($atomic instanceof TNamedObject && \is_a($atomic->value, Relation::class, true)) {
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
