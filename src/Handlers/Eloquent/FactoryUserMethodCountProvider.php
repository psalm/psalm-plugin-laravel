<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
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
 * ({@see indexDescendants()}, built from the factories the synthesis already walked); a final class has none.
 * A `final` modifier on the method alone proves nothing, because the chain's `state()` can still be overridden.
 *
 * The verdict also needs {@see runsLaravelCountPath()}, the ONE hierarchy-wide gate: the receiver and every
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
 * Registered per synthesized class by {@see FactoryModelBindingHandler}: Psalm consults a provider only for the
 * receiver's class or the method's declaring class, never for ancestors, and the class-level `Factory` provider
 * ({@see FactoryCountTypeProvider}) is never asked about a user's own method.
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
final class FactoryUserMethodCountProvider
{
    /**
     * `Factory` methods that rebuild the factory through `newInstance()` with the count carried over
     * (`sequence()` and `crossJoinSequence()` only add a state; `forEachSequence()` sets a count and is absent).
     * Names served by `__call` (`for*` / `has*` forwarders, macros) are NOT here: Laravel checks the macro
     * registry first, so a macro named `hasBatch` can change the count.
     *
     * @var list<lowercase-string>
     */
    private const COUNT_KEEPING_METHODS = [
        'state', 'sequence', 'crossjoinsequence', 'set', 'for', 'has', 'hasattached', 'aftermaking',
        'aftercreating', 'connection', 'recycle', 'withoutparents',
    ];

    /**
     * Per declaring method id: the methods called in its `return` chains, or false when the body fails the rule,
     * with the `MethodStorage` it was read from. A rescanned file brings a new storage, so its proof is redone.
     *
     * @var array<string, array{MethodStorage, list<lowercase-string>|false}>
     */
    private static array $chains = [];

    /**
     * Known `Factory` descendants per ancestor class (lowercase name => names).
     *
     * @var array<lowercase-string, list<string>>
     */
    private static array $descendants = [];

    /**
     * `true`: the class owns its count, it declares a `Factory` method (see {@see declaresLaravelMethod()}).
     * `false`: only a known descendant does (ancestors other than `Factory` itself).
     *
     * @var array<lowercase-string, bool>
     */
    private static array $owners = [];

    public static function reset(): void
    {
        self::$chains = [];
        self::$descendants = [];
        self::$owners = [];
    }

    /**
     * Rebuilds the descendant index from all `Factory` subclasses: `$descendants` per ancestor and `$owners`
     * ({@see runsLaravelCountPath()}).
     *
     * Like every other `AfterCodebasePopulated` artifact of this plugin (the factory binding, `ModelMetadataRegistry`) the
     * index is not refreshed by a language-server `reloadFiles()`; only the per-method proofs follow a rescan.
     *
     * @param list<ClassLikeStorage> $factories
     */
    public static function indexDescendants(Codebase $codebase, array $factories): void
    {
        self::reset();
        $factoryLc = \strtolower(Factory::class);
        // Every method `Factory` declares, traits included, read from its storage; `definition()` is exempt (abstract).
        $laravelMethods = \array_diff_key($codebase->classlike_storage_provider->get(Factory::class)->declaring_method_ids, ['definition' => true]);

        foreach ($factories as $storage) {
            $owns = self::declaresLaravelMethod($codebase, $storage, $laravelMethods);
            if ($owns) {
                self::$owners[\strtolower($storage->name)] = true;
            }

            foreach ($storage->parent_classes as $lcAncestor => $_) {
                self::$descendants[$lcAncestor][] = $storage->name;
                if ($owns && $lcAncestor !== $factoryLc) {
                    self::$owners[$lcAncestor] ??= false;
                }
            }
        }
    }

    /**
     * True when the class owns its count: for a method Laravel's `Factory` declares (its traits `Macroable`,
     * `ForwardsCalls`, `Conditionable` included), other than `definition()` and a `configure()` whose body passes
     * the keep rule, the class resolves the name to anything but that exact method (an override, or a trait alias). Any of them can set a count (`__construct`, `count()`, `state()`,
     * `newInstance()`), or run before a rebuild that captures it (`modelName()`, `newModel()`, `guessRelationship()`).
     *
     * @param array<lowercase-string, MethodIdentifier> $laravelMethods Factory's own declaring ids by method name
     */
    private static function declaresLaravelMethod(Codebase $codebase, ClassLikeStorage $storage, array $laravelMethods): bool
    {
        foreach ($laravelMethods as $name => $laravel) {
            $declaring = $storage->declaring_method_ids[$name] ?? null;
            if ($declaring !== null
                && (string) $declaring !== (string) $laravel
                && ($name !== 'configure' || !self::keepsCount($codebase, $storage, $name))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when `$name` resolves on the class to the very method `Factory` itself declares (not an override, not a
     * trait method aliased over it).
     *
     * @psalm-capabilities read-props
     */
    private static function isFactoryMethod(Codebase $codebase, ClassLikeStorage $storage, string $name): bool
    {
        $laravel = $codebase->classlike_storage_provider->get(Factory::class)->declaring_method_ids[$name] ?? null;
        $declaring = $storage->declaring_method_ids[$name] ?? null;

        return $laravel !== null && $declaring !== null && (string) $declaring === (string) $laravel;
    }

    /**
     * The one gate for a definite count: true when no class the receiver may be at runtime (`$exact`: the class
     * itself, a literal `Class::times()` / `final` receiver; otherwise it and every known descendant)
     * owns its count ({@see declaresLaravelMethod()}). A bare `Factory` type is the user's own contract and stays trusted.
     */
    public static function runsLaravelCountPath(ClassLikeStorage $class, bool $exact = false): bool
    {
        $owner = self::$owners[\strtolower($class->name)] ?? null;

        return $owner === null || ($exact && $owner === false);
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $codebase = $event->getSource()->getCodebase();
        $name = $event->getMethodNameLowercase();
        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $storage = $codebase->classlike_storage_provider->get($calledClass);
        $declaring = $storage->declaring_method_ids[$name] ?? null;
        if (!FactoryModelBindingHandler::hasSynthesizedCount($storage) || $declaring === null) {
            return null;
        }

        $declared = self::methodStorage($codebase, $declaring)?->return_type;
        if (!$declared instanceof Union) {
            return null;
        }

        if (self::isFactoryMethod($codebase, $storage, $name)) {
            // The producers answer in `FactoryCountTypeProvider`. Any other Laravel method keeps the count only
            // while no descendant declares a Laravel method (`set()` may be overridden under an `F<null>` receiver).
            return \in_array($name, FactoryCountTypeProvider::PRODUCERS, true) || self::runsLaravelCountPath($storage)
                ? null
                : self::widened($codebase, $calledClass, $declared, $event);
        }

        $keeps = self::keepsOnEveryDispatch($codebase, $storage, $name);
        $single = $declared->isSingle() ? $declared->getSingleAtomic() : null;
        if ($single instanceof TNamedObject && !self::isStatic($single) && self::isOwnClass($single, $declaring->fq_class_name)) {
            // `self` / the declaring class / bare `Factory`: the receiver itself when the body keeps the count.
            $receiver = FactoryCountTypeProvider::receiver($event, $calledClass);

            return $keeps && $receiver instanceof TNamedObject ? new Union([$receiver]) : null;
        }

        return $keeps ? null : self::widened($codebase, $calledClass, $declared, $event);
    }

    private static function widened(Codebase $codebase, string $class, Union $declared, MethodReturnTypeProviderEvent $event): ?Union
    {
        $unknown = FactoryCountTypeProvider::withCount($codebase, $class, FactoryCountTypeProvider::unknownCountUnion(), $event);

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
                $type = new TGenericObject(
                    $this->receiver->value,
                    $this->receiver->type_params,
                    false,
                    $this->receiver->is_static,
                    $this->receiver->extra_types + $type->extra_types,
                );
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

    /** @psalm-capabilities read-props */
    private static function methodStorage(Codebase $codebase, MethodIdentifier $id): ?MethodStorage
    {
        try {
            return $codebase->methods->getStorage($id);
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }
    }

    /**
     * The rule on the receiver's implementation and on every known descendant's: a descendant may be what a
     * `F<null>` parameter holds at runtime. Unreadable storage is not proven. Not cached: the verdict is a few
     * map lookups per class, and the per-method body proof is cached with the `MethodStorage` it was read from, which a rescanned file replaces.
     */
    private static function keepsOnEveryDispatch(Codebase $codebase, ClassLikeStorage $receiver, string $name): bool
    {
        return self::runsLaravelCountPath($receiver)
            && self::keepsCount($codebase, $receiver, $name)
            && self::descendantsKeep($codebase, \strtolower($receiver->name), $name);
    }

    private static function descendantsKeep(Codebase $codebase, string $lcReceiver, string $name): bool
    {
        foreach (self::$descendants[$lcReceiver] ?? [] as $descendant) {
            try {
                $storage = $codebase->classlike_storage_provider->get($descendant);
            } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                return false;
            }

            if (!self::keepsCount($codebase, $storage, $name)) {
                return false;
            }
        }

        return true;
    }

    private static function keepsCount(Codebase $codebase, ClassLikeStorage $receiver, string $name): bool
    {
        $declaring = $receiver->declaring_method_ids[$name] ?? null;
        $method = $declaring instanceof MethodIdentifier ? self::methodStorage($codebase, $declaring) : null;
        if (!$method instanceof MethodStorage) {
            return false;
        }

        $cached = self::$chains[(string) $declaring] ?? null;
        if ($cached === null || $cached[0] !== $method) {
            $cached = self::$chains[(string) $declaring] = [$method, self::returnedCalls($codebase, $method) ?? false];
        }

        $calls = $cached[1];
        if ($calls === false) {
            return false;
        }

        foreach ($calls as $call) {
            if (!\in_array($call, self::COUNT_KEEPING_METHODS, true) || !self::isFactoryMethod($codebase, $receiver, $call)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The lowercase method names called across the body's `return` statements, or null when the body has any
     * other statement, any return is not `$this` / a pure method chain on it, or there is no body (abstract,
     * interface, stub, vendor stub).
     *
     * @return list<lowercase-string>|null
     */
    private static function returnedCalls(Codebase $codebase, MethodStorage $method): ?array
    {
        $location = $method->stmt_location;
        if (!$location instanceof \Psalm\CodeLocation) {
            return null;
        }

        $node = (new NodeFinder())->findFirst(
            $codebase->getStatementsForFile($location->file_path),
            static fn(Node $node): bool => $node instanceof ClassMethod && $node->getStartFilePos() === $location->raw_file_start,
        );
        if (!$node instanceof ClassMethod || $node->stmts === null || $node->stmts === []) {
            return null;
        }

        // By-value parameters whose DECLARED type (native or docblock) cannot run factory code ({@see isInertType()}).
        $inert = [];
        foreach ($method->params as $param) {
            $inert[$param->name] = !$param->by_ref && !$param->is_variadic && $param->type instanceof Union && self::isInertType($codebase, $param->type);
        }

        $calls = [];
        foreach ($node->stmts as $stmt) {
            $chain = $stmt instanceof Return_ && $stmt->expr instanceof Expr ? self::chainOnThis($stmt->expr, $inert) : null;
            if ($chain === null) {
                return null;
            }

            $calls = [...$calls, ...$chain];
        }

        return $calls;
    }

    /**
     * `$this` is the empty chain, `$this->a()->b()` is `[a, b]`; anything else (static call, `parent::`,
     * nullsafe, dynamic name, another root, a non-inert argument) is null.
     *
     * @param array<string, bool> $inert parameter name => its declared type cannot run factory code
     * @return list<lowercase-string>|null
     * @psalm-mutation-free
     */
    private static function chainOnThis(Expr $expr, array $inert): ?array
    {
        if ($expr instanceof Expr\Variable) {
            return $expr->name === 'this' ? [] : null;
        }

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return null;
        }

        foreach ($expr->args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || !self::isInert($arg->value, $inert)) {
                return null;
            }
        }

        $inner = self::chainOnThis($expr->var, $inert);

        return $inner === null ? null : [...$inner, \strtolower($expr->name->name)];
    }

    /** @psalm-mutation-free */
    private static function isInertType(Codebase $codebase, Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            $items = match (true) {
                $atomic instanceof TArray => $atomic->type_params,
                $atomic instanceof TKeyedArray => [...$atomic->properties, ...($atomic->fallback_params ?? [])],
                $atomic instanceof Scalar, $atomic instanceof TNull, $atomic instanceof TClosure => [],
                $atomic instanceof TNamedObject && ClassLineage::isA($codebase, $atomic->value, Model::class) => [],
                default => null,
            };

            if ($items === null || \array_filter($items, static fn(Union $item): bool => !self::isInertType($codebase, $item)) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * An argument that cannot touch the factory, judged by syntax and the method's own parameters: a literal, a
     * constant by name, `X::class` / `X::CONST` with a NAME class, a negative number, an array of such items, a
     * closure (whose body runs later on attributes), or a variable that is an inert parameter
     * ({@see returnedCalls()}). Anything that evaluates an expression first (`(expr)::class`, a call, `new`, an
     * assignment, an include, `$a->b->c`) is not, and neither is `$this`, a local, a property read (which runs
     * `__get()` when the property is undeclared), nor an unpacked argument (which iterates user code).
     *
     * @param array<string, bool> $inert
     * @psalm-mutation-free
     */
    private static function isInert(Expr $expr, array $inert): bool
    {
        if ($expr instanceof Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item->unpack || !self::isInert($item->value, $inert) || ($item->key instanceof Expr && !self::isInert($item->key, $inert))) {
                    return false;
                }
            }

            return true;
        }

        return match (true) {
            $expr instanceof Expr\Variable => \is_string($expr->name) && ($inert[$expr->name] ?? false),
            $expr instanceof Node\Scalar => !$expr instanceof Node\Scalar\InterpolatedString,
            $expr instanceof Expr\ConstFetch, $expr instanceof Node\FunctionLike => true,
            $expr instanceof Expr\ClassConstFetch => $expr->class instanceof Node\Name && $expr->name instanceof Identifier,
            $expr instanceof Expr\UnaryMinus => $expr->expr instanceof Node\Scalar\Int_ || $expr->expr instanceof Node\Scalar\Float_,
            default => false,
        };
    }
}
