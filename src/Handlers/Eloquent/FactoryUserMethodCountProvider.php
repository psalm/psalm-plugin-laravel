<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\FactoryCount;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

/**
 * A user method on a factory with a synthesized `TCount` hands the receiver's count to its caller, but only
 * when its body provably keeps it. The rule covers the WHOLE body: every statement is `return <expr>;`, and
 * every returned expression is `$this` or a call chain rooted at `$this` that uses nothing but Laravel's own
 * count-keeping `Factory` methods, with inert arguments (variables, literals, arrays of them, closures: a
 * closure given to `state()` / `sequence()` runs on attributes, never on the factory). A property write, an
 * assignment, a call statement, `unset`, control flow, `count()`, `new static`, `parent::`, a helper, no body:
 * any of it means the count is unknown.
 *
 * The rule is also an answer about every OTHER implementation the receiver can dispatch to: a parameter typed
 * `F<null>` may hold a subclass whose override of the method (or of a Laravel method it calls) changes the count.
 * So the verdict is the rule on the receiver's own method AND on each known descendant's implementation
 * ({@see FactoryCount::indexDescendants()}, built from the factories the synthesis already walked); a final class has none.
 * A `final` modifier on the method alone proves nothing, because the chain's `state()` can still be overridden.
 *
 * The verdict also needs {@see FactoryCount::runsLaravelCountPath()}, the ONE hierarchy-wide gate: the receiver and every
 * known descendant (and every user ancestor) declare NO method that Laravel's `Factory` declares, apart from
 * `definition()` and a `configure()` that passes the same body rule. The gate also applies to Laravel's own
 * fluent methods (`set()`, `state()`, `has()`, ...): a descendant's override changes what an `F<null>` holds.
 *
 * What the provider answers depends on the declared return:
 *  - `static` / `$this`: rule holds, null (Psalm's own `static` substitution keeps the receiver's count and
 *    its intersections); rule fails, the receiver at an unknown count (`&static` and intersections kept).
 *  - `self`, the declaring class, or bare `Factory`: rule holds, the RECEIVER type with its count, so a later
 *    `admin()` stays callable and the count survives; rule fails, null (today's declared type, whose
 *    default `int|null` count is sound).
 *  - anything else (`static|T`, `static|null`, `list<static>`, `Foo`): rule holds, null, so Psalm substitutes the
 *    method's templates; rule fails, every `static`, at any depth, becomes the receiver at an unknown count and any
 *    top-level template atomic becomes its bound, because the provider has no template result to substitute from.
 *
 * Registered per synthesized class (see {@see afterCodebasePopulated()}): Psalm consults a provider only for the
 * receiver's class or the method's declaring class, never for ancestors, and the class-level `Factory` provider
 * (`FactoryCountTypeProvider`) is never asked about a user's own method.
 *
 * Accepted soundness gap (receiver mutation): a failed proof widens the factory the method RETURNS, never the
 * variable it was called on. `$f->change();` with a discarded result (or a reused factory whose state closure
 * changes `$this->count`) leaves `$f` at its old count; Psalm keeps the receiver's own type across a call.
 * Pinned by `FactoryCountReceiverMutationKnownLimitation.phpt`.
 *
 * `when()` / `unless()` are NOT in the keep list: their callback's return value replaces `$this`
 * (`$callback($this, $value) ?? $this`) and may carry another count.
 *
 * @internal
 */
final class FactoryUserMethodCountProvider implements AfterCodebasePopulatedInterface
{
    /**
     * Registers the provider on every class that received a synthesized `TCount`. Runs after
     * `FactoryModelBindingHandler` (registered before this class in `Plugin::registerHandlers()`), which sets the marker.
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (FactoryCount::hasSynthesizedCount($storage)) {
                $codebase->methods->return_type_provider->registerClosure($storage->name, self::getMethodReturnType(...));
            }
        }
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $codebase = $event->getSource()->getCodebase();
        $name = $event->getMethodNameLowercase();
        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $storage = $codebase->classlike_storage_provider->get($calledClass);
        $declaring = $storage->declaring_method_ids[$name] ?? null;
        if (!FactoryCount::hasSynthesizedCount($storage) || $declaring === null) {
            return null;
        }

        $declared = FactoryCount::methodStorage($codebase, $declaring)?->return_type;
        if (!$declared instanceof Union) {
            return null;
        }

        if (FactoryCount::isFactoryMethod($codebase, $storage, $name)) {
            // The producers answer in `FactoryCountTypeProvider`. Any other Laravel method keeps the count only
            // while no descendant declares a Laravel method (`set()` may be overridden under an `F<null>` receiver).
            return \in_array($name, FactoryCount::PRODUCERS, true) || FactoryCount::runsLaravelCountPath($storage)
                ? null
                : self::widened($codebase, $calledClass, $declared, $event);
        }

        $keeps = self::keepsOnEveryDispatch($codebase, $storage, $name);
        $single = $declared->isSingle() ? $declared->getSingleAtomic() : null;
        if ($single instanceof TNamedObject && !self::isStatic($single) && self::isOwnClass($single, $declaring->fq_class_name)) {
            // `self` / the declaring class / bare `Factory`: the receiver itself when the body keeps the count.
            $receiver = FactoryCount::receiver($event, $calledClass);

            return $keeps && $receiver instanceof TNamedObject ? new Union([$receiver]) : null;
        }

        return $keeps ? null : self::widened($codebase, $calledClass, $declared, $event);
    }

    private static function widened(Codebase $codebase, string $class, Union $declared, MethodReturnTypeProviderEvent $event): ?Union
    {
        $unknown = FactoryCount::withCount($codebase, $class, FactoryCount::unknownCountUnion(), $event);

        $receiver = $unknown instanceof Union ? $unknown->getSingleAtomic() : null;

        return $receiver instanceof TGenericObject ? self::widenStatic($declared, $receiver) : null;
    }

    /**
     * `declared` with every `static` at ANY depth (nested in an array, generic, callable or shape) replaced by the
     * receiver at an unknown count; null when it has none. Only `static` is replaced, so a declared `static|null`
     * keeps its `null`. A top-level method template cannot be solved here, so it falls back to its bound.
     */
    private static function widenStatic(Union $declared, TGenericObject $receiver): ?Union
    {
        $atomics = [];
        foreach ($declared->getAtomicTypes() as $atomic) {
            $atomics = [...$atomics, ...($atomic instanceof TTemplateParam ? \array_values($atomic->as->getAtomicTypes()) : [$atomic])];
        }

        $widened = new Union($atomics);
        $visitor = new class ($receiver) extends MutableTypeVisitor {
            public bool $replaced = false;

            public function __construct(private readonly TGenericObject $receiver) {}

            /** @psalm-capabilities read-props|write-this-props|write-refs */
            #[\Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if (!$type instanceof TNamedObject || !FactoryUserMethodCountProvider::isStatic($type)) {
                    return null;
                }

                // Only the factory atomic's count changes: members the declared `F&Marker&static` intersects stay.
                $type = (new TGenericObject(
                    $this->receiver->value,
                    $this->receiver->type_params,
                    false,
                    $this->receiver->is_static,
                    $this->receiver->extra_types + $type->extra_types,
                ))->setIsStatic($this->receiver->is_static, $this->receiver->is_static_resolved);
                $this->replaced = true;

                return self::DONT_TRAVERSE_CHILDREN;
            }
        };
        $visitor->traverse($widened);

        return $visitor->replaced ? $widened : null;
    }

    /**
     * `self` (resolved by Psalm to the declaring class), the declaring class itself, or the bare `Factory`,
     * with no generics or intersections.
     *
     * @psalm-pure
     */
    private static function isOwnClass(TNamedObject $atomic, string $declaringClass): bool
    {
        return !$atomic instanceof TGenericObject
            && $atomic->extra_types === []
            && \in_array(\strtolower($atomic->value), ['self', \strtolower($declaringClass), \strtolower(Factory::class)], true);
    }

    /**
     * A native `: static` is flagged `is_static`; a docblock `@return static` / `$this` is still the unexpanded
     * `static` keyword.
     *
     * @psalm-pure
     */
    public static function isStatic(Atomic $atomic): bool
    {
        return $atomic instanceof TNamedObject && ($atomic->is_static || \strtolower($atomic->value) === 'static');
    }

    /**
     * The rule on the receiver's implementation and on every known descendant's: a descendant may be what a
     * `F<null>` parameter holds at runtime. Unreadable storage is not proven. Not cached: the verdict is a few
     * map lookups per class, and the per-method body proof is cached with the `MethodStorage` it was read from, which a rescanned file replaces.
     */
    private static function keepsOnEveryDispatch(Codebase $codebase, ClassLikeStorage $receiver, string $name): bool
    {
        return FactoryCount::runsLaravelCountPath($receiver)
            && FactoryCount::keepsCount($codebase, $receiver, $name)
            && self::descendantsKeep($codebase, \strtolower($receiver->name), $name);
    }

    private static function descendantsKeep(Codebase $codebase, string $lcReceiver, string $name): bool
    {
        foreach (FactoryCount::descendantsOf($lcReceiver) as $descendant) {
            try {
                $storage = $codebase->classlike_storage_provider->get($descendant);
            } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                return false;
            }

            if (!FactoryCount::keepsCount($codebase, $storage, $name)) {
                return false;
            }
        }

        return true;
    }
}
