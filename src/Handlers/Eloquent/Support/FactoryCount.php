<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TNumericString;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * The shared contract behind the factory count (#2188): the four factory handlers
 * (`FactoryModelBindingHandler`, `FactoryCountTypeProvider`, `FactoryUserMethodCountProvider`,
 * `ModelFactoryMethodTypeProvider`) depend on this class and never on each other.
 *
 * It owns:
 *  - the synthesis marker on a class storage that received the plugin-synthesized `TCount`
 *    ({@see markSynthesized()} / {@see hasSynthesizedCount()});
 *  - the descendant index and the ONE hierarchy-wide gate ({@see indexDescendants()}, {@see runsLaravelCountPath()});
 *  - the body rule a user method must pass to keep the receiver's count ({@see keepsCount()});
 *  - the count classification of `factory()` / `count()` / `times()` arguments and the single / plural /
 *    unknown count unions ({@see resolveFactoryCountArgument()}, {@see resolveCountArgument()});
 *  - {@see withCount()}, the concrete factory class carrying a count.
 *
 * "Some non-null count" is the plural sentinel `int<2, max>`: Laravel returns a `Collection` for every
 * non-null count (0 and 1 included), and a literal sentinel trips a Psalm template rewrite bug
 * (see decisions.md "Factories").
 *
 * Per-run state (the descendant index, the per-method proofs) is rebuilt by {@see indexDescendants()} and
 * cleared by {@see reset()}, registered in `Plugin::resetInvocationState()`.
 *
 * @internal
 */
final class FactoryCount
{
    /**
     * The `Factory` methods that set the count and answer in `FactoryCountTypeProvider`; every other method of a
     * synthesized class is answered by `FactoryUserMethodCountProvider`.
     *
     * @var list<lowercase-string>
     */
    public const PRODUCERS = ['count', 'times', 'foreachsequence', 'new'];

    /**
     * Marker on the storage that received a plugin-synthesized `TCount`, set at synthesis time. A user's own
     * `@template TCount` never carries it, however it maps into `Factory`, so no provider rewrites it.
     */
    private const SYNTHESIZED_MARKER = 'laravel_factory_synthesized_count';

    /**
     * `Factory` methods that rebuild the factory through `newInstance()` with the count carried over
     * (`sequence()` and `crossJoinSequence()` only add a state; `forEachSequence()` sets a count and is absent).
     * Names served by `__call` (`for*` / `has*` forwarders, macros) are NOT here: Laravel checks the macro
     * registry first, so a macro named `hasBatch` can change the count.
     *
     * Keyed for `isset()`: a negated `in_array()` over this many literals inside `||` makes Psalm's analysis of
     * the caller exponential.
     *
     * @todo-by https://github.com/vimeo/psalm/issues/13603 a plain list with in_array() is fine again
     *
     * @var array<lowercase-string, true>
     */
    private const COUNT_KEEPING_METHODS = [
        'state' => true, 'sequence' => true, 'crossjoinsequence' => true, 'set' => true, 'for' => true,
        'has' => true, 'hasattached' => true, 'aftermaking' => true, 'aftercreating' => true,
        'connection' => true, 'recycle' => true, 'withoutparents' => true,
    ];

    /**
     * Cached single-instance Unions for the three TCount shapes. The providers fire on every factory call in the
     * analysed codebase, so re-allocating these per call adds up.
     */
    private static ?Union $singleCountUnion = null;

    private static ?Union $pluralCountUnion = null;

    private static ?Union $unknownCountUnion = null;

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

    /** @psalm-mutation-free */
    public static function hasSynthesizedCount(ClassLikeStorage $storage): bool
    {
        return isset($storage->custom_metadata[self::SYNTHESIZED_MARKER]);
    }

    /** Records ownership of the synthesized `TCount` on the storage. */
    public static function markSynthesized(ClassLikeStorage $storage): void
    {
        $storage->custom_metadata[self::SYNTHESIZED_MARKER] = true;
    }

    // ----- Hierarchy gate -----

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
     * The one gate for a definite count: true when no class the receiver may be at runtime (`$exact`: the class
     * itself, a literal `Class::times()` / `final` receiver; otherwise it and every known descendant)
     * owns its count ({@see declaresLaravelMethod()}). A bare `Factory` type is the user's own contract and stays trusted.
     */
    public static function runsLaravelCountPath(ClassLikeStorage $class, bool $exact = false): bool
    {
        $owner = self::$owners[\strtolower($class->name)] ?? null;

        return $owner === null || ($exact && $owner === false);
    }

    /**
     * True when `$name` resolves on the class to the very method `Factory` itself declares (not an override, not a
     * trait method aliased over it).
     *
     * @psalm-capabilities read-props
     */
    public static function isFactoryMethod(Codebase $codebase, ClassLikeStorage $storage, string $name): bool
    {
        $laravel = $codebase->classlike_storage_provider->get(Factory::class)->declaring_method_ids[$name] ?? null;
        $declaring = $storage->declaring_method_ids[$name] ?? null;

        return $laravel !== null && $declaring !== null && (string) $declaring === (string) $laravel;
    }

    /**
     * The known descendants of the class (lowercase name), empty for a final class or an unknown one.
     *
     * @return list<string>
     */
    public static function descendantsOf(string $lcClass): array
    {
        return self::$descendants[$lcClass] ?? [];
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

    // ----- The body rule -----

    /** @psalm-capabilities read-props */
    public static function methodStorage(Codebase $codebase, MethodIdentifier $id): ?MethodStorage
    {
        try {
            return $codebase->methods->getStorage($id);
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }
    }

    /**
     * True when the receiver's own implementation of `$name` passes the body rule (see
     * `FactoryUserMethodCountProvider`): every statement is `return <expr>;` and every expression is `$this` or a
     * call chain on it that uses nothing but Laravel's own count-keeping `Factory` methods with inert arguments.
     * Unreadable storage is not proven.
     */
    public static function keepsCount(Codebase $codebase, ClassLikeStorage $receiver, string $name): bool
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
            if (!isset(self::COUNT_KEEPING_METHODS[$call]) || !self::isFactoryMethod($codebase, $receiver, $call)) {
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

    // ----- Count classification -----

    /**
     * Laravel's `HasFactory::factory($count, $state)` counts the factory with `is_numeric($count) ? $count : null`:
     * int/float/numeric-string (0 and 1 included) is plural, null/array/callable/bool is a state or no count.
     * The count is the first positional or the `count:` argument; any unpack, or a name other than
     * `count:` / `state:`, leaves it unknown.
     *
     * @param list<\PhpParser\Node\Arg> $callArgs
     */
    public static function resolveFactoryCountArgument(array $callArgs, NodeTypeProvider $nodeTypeProvider): Union
    {
        $countArg = null;
        foreach ($callArgs as $index => $arg) {
            $name = $arg->name?->name;
            if ($arg->unpack || (!\in_array($name, [null, 'count', 'state'], true))) {
                return self::unknownCountUnion();
            }

            if ($name === 'count' || ($name === null && $index === 0)) {
                $countArg = $arg;
            }
        }

        if ($countArg === null) {
            return self::singleCountUnion();
        }

        $argType = $nodeTypeProvider->getType($countArg->value);
        if (!$argType instanceof Union) {
            return self::unknownCountUnion();
        }

        $isPlural = null;
        foreach ($argType->getAtomicTypes() as $atomic) {
            $atomicPlural = match (true) {
                $atomic instanceof TInt, $atomic instanceof TFloat, $atomic instanceof TNumericString, $atomic instanceof TNumeric => true,
                $atomic instanceof TLiteralString => \is_numeric($atomic->value),
                $atomic instanceof TNull, $atomic instanceof TArray, $atomic instanceof TKeyedArray, $atomic instanceof TBool,
                $atomic instanceof TCallable, $atomic instanceof TClosure => false,
                default => null,
            };

            if ($atomicPlural === null || ($isPlural !== null && $isPlural !== $atomicPlural)) {
                return self::unknownCountUnion();
            }

            $isPlural = $atomicPlural;
        }

        return $isPlural ? self::pluralCountUnion() : self::singleCountUnion();
    }

    /**
     * Map the count argument of `count()` / `times()` to the TCount template type:
     *  - missing or `null` literal → `null` (single-mode branch)
     *  - any non-null int (literal `0`/`1`/`N`, plain `int`, `int<a, b>`,
     *    `positive-int`, etc.) → `int<2, max>` (collection branch, Laravel's
     *    `Factory::make()` returns a collection for every non-null count)
     *  - `int|null`, an unpack, or any other atomic → `null|int<2, max>` (forces the
     *    conditional to a union of both branches)
     *
     * `int<2, max>` is just a representative non-null value, standing for "a count".
     *
     * @param list<\PhpParser\Node\Arg> $callArgs
     */
    public static function resolveCountArgument(array $callArgs, NodeTypeProvider $nodeTypeProvider): Union
    {
        $arg = $callArgs[0] ?? null;
        if ($arg === null) {
            return self::singleCountUnion();
        }

        $argType = $arg->unpack ? null : $nodeTypeProvider->getType($arg->value);
        if (!$argType instanceof Union) {
            return self::unknownCountUnion();
        }

        $hasNull = false;
        $hasNonNullInt = false;

        foreach ($argType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                $hasNull = true;
                continue;
            }

            // TLiteralInt extends TInt, so this branch also covers literal
            // ints (`0`, `1`, `N`), TIntRange, and any future TInt subclass.
            if ($atomic instanceof TInt) {
                $hasNonNullInt = true;
                continue;
            }

            // Unexpected atomic (e.g., string from a misuse via __callStatic).
            // Fall back to the union shape so neither branch is silently dropped.
            return self::unknownCountUnion();
        }

        return match (true) {
            $hasNull && !$hasNonNullInt => self::singleCountUnion(),
            $hasNonNullInt && !$hasNull => self::pluralCountUnion(),
            default => self::unknownCountUnion(),
        };
    }

    public static function singleCountUnion(): Union
    {
        return self::$singleCountUnion ??= new Union([new TNull()]);
    }

    public static function pluralCountUnion(): Union
    {
        return self::$pluralCountUnion ??= new Union([new TIntRange(2, null)]);
    }

    public static function unknownCountUnion(): Union
    {
        return self::$unknownCountUnion ??= new Union([new TNull(), new TIntRange(2, null)]);
    }

    // ----- Building the answer -----

    /**
     * The factory class carrying `$count`, or null when the class does not own a plugin-synthesized `TCount`
     * (explicit `@extends Factory<M, X>`, a user's own `@template TCount`, an overridden count method) or has
     * other template slots the receiver cannot supply. Every other slot is taken from the receiver's own
     * template arguments by position in the CLASS's template list, with TCount located by name, never by position.
     * Only the factory atomic's count is widened: the receiver's late-static marker (`&static`) and every other
     * intersection member (`F<null>&Marker`) are carried over.
     */
    public static function withCount(Codebase $codebase, string $factoryClass, Union $count, ?MethodReturnTypeProviderEvent $event = null): ?Union
    {
        if (!$codebase->classlike_storage_provider->has($factoryClass)) {
            return null;
        }

        $storage = $codebase->classlike_storage_provider->get($factoryClass);
        if (!self::hasSynthesizedCount($storage)) {
            return null;
        }

        $receiver = $event instanceof MethodReturnTypeProviderEvent ? self::receiver($event, $storage->name) : null;
        $receiverParams = $receiver instanceof TGenericObject ? $receiver->type_params : $event?->getTemplateTypeParameters();
        $slots = \array_keys($storage->template_types ?? []);

        /** @var non-empty-list<Union> $params */
        $params = [];
        foreach ($slots as $position => $slot) {
            $param = $slot === 'TCount' ? $count : (\count($receiverParams ?? []) === \count($slots) ? $receiverParams[$position] ?? null : null);
            if (!$param instanceof Union) {
                return null;
            }

            $params[] = $param;
        }

        return new Union([(new TGenericObject($storage->name, $params, false, $receiver?->is_static ?? false, $receiver?->extra_types ?? []))
            ->setIsStatic($receiver?->is_static ?? false, $receiver?->is_static_resolved ?? false)]);
    }

    /**
     * The receiver of the call when it is a single object of `$class` (`$this->count(3)` in a `static`-returning
     * method has `&static`, or the declared `static` return is reported as more specific than what was inferred),
     * or the `static` keyword of `static::times(3)`. Null for a union, a template or a class-string receiver.
     */
    public static function receiver(MethodReturnTypeProviderEvent $event, string $class): ?TNamedObject
    {
        $stmt = $event->getStmt();
        if ($stmt instanceof StaticCall) {
            return $stmt->class instanceof Name && \strtolower($stmt->class->name) === 'static' ? new TNamedObject($class, true) : null;
        }

        $type = $event->getSource()->getNodeTypeProvider()->getType($stmt->var);
        $atomic = $type instanceof Union && $type->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject && \strtolower($atomic->value) === \strtolower($class) ? $atomic : null;
    }

    /**
     * `static::new()`, `$class::new()` or an instance receiver may name a subclass that overrides the
     * method and presets a count, which `final` rules out. Callers then answer "count unknown".
     *
     * @psalm-mutation-free
     */
    public static function isLateBound(MethodReturnTypeProviderEvent $event, Codebase $codebase, string $class): bool
    {
        $stmt = $event->getStmt();
        $literalClass = $stmt instanceof StaticCall
            && $stmt->class instanceof Name
            && !\in_array(\strtolower($stmt->class->name), ['static', 'self', 'parent'], true);

        return !$literalClass && !$codebase->classlike_storage_provider->get($class)->final;
    }

    /**
     * The base `Factory<TModel, $count>`, the answer for a factory the plugin does not own the count of (no
     * synthesized `TCount`): an explicit `@extends Factory<M, X>`, a user's own `@template TCount`, or a class
     * that declares a Laravel method.
     *
     * @psalm-pure
     */
    public static function baseFactory(Union $model, Union $count): Union
    {
        return new Union([new TGenericObject(Factory::class, [$model, $count])]);
    }

    /**
     * The TModel a class binds `Factory` to, when it is a Model type (walks `template_extended_params`). Null if
     * the class isn't a Factory subclass or the binding can't be resolved.
     *
     * `ClassLikeStorageProvider::get()` lowercases internally, so the FQCN is passed through untouched.
     * `template_extended_params` is keyed on the canonical class name (`$parent_storage->name`), which matches
     * `Factory::class` for any vendor-stable Laravel install.
     *
     * @psalm-mutation-free
     */
    public static function modelBoundBy(string $factoryClass, Codebase $codebase): ?Union
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($factoryClass);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $extended = $storage->template_extended_params[Factory::class] ?? null;
        if (!\is_array($extended)) {
            return null;
        }

        $modelType = $extended['TModel'] ?? null;

        return self::isModelType($modelType, $codebase) ? $modelType : null;
    }

    /**
     * Every atomic is Model or a Model subclass, or a template param bounded by one (the `TModel` of a generic
     * factory subclass, `@extends Factory<TModel>`). Lineage from Psalm's storage ({@see ClassLineage}):
     * PHPT fixture models declared inline are scanned but not autoloadable.
     *
     * @psalm-mutation-free
     */
    public static function isModelType(?Union $type, Codebase $codebase): bool
    {
        if (!$type instanceof Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TTemplateParam) {
                if (!self::isModelType($atomic->as, $codebase)) {
                    return false;
                }

                continue;
            }

            if (!$atomic instanceof TNamedObject || !ClassLineage::isA($codebase, $atomic->value, Model::class)) {
                return false;
            }
        }

        return true;
    }
}
