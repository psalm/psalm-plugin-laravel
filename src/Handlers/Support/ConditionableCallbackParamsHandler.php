<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Traits\Conditionable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\Statements\ExpressionAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\AssertionReconciler;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Atomic\TVoid;
use Psalm\Type\Reconciler;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

/**
 * Types the `$callback` / `$default` params of {@see Conditionable::when()} / `unless()` per call
 * site as `callable(<receiver>, <truthy|falsy $value>)`, mirroring Laravel's
 * `$callback($this, $value)` / `$default($this, $value)` (slots swapped for `unless()`). A stub
 * cannot express this: the receiver needs the call's generics and the value slot needs the
 * argument's type narrowed by truthiness.
 *
 * Why the moving parts:
 *  - Params providers dispatch on the CALLED class, never the declaring trait (unlike the
 *    return-type provider {@see ConditionableWhenHandler}), so a closure is registered per host
 *    after population. Hosts declaring their own when()/unless() (Enumerable, Container, ...)
 *    are skipped automatically.
 *  - The provider event carries no call node, and a class-name-only receiver loses generics, so
 *    {@see beforeExpressionAnalysis()} stashes the call keyed by its first Arg to read the
 *    receiver's node type.
 *  - Params are fetched before args are analyzed, so `$value` is analyzed here first, on a CLONED
 *    context: Psalm analyzes the arg again on the live context afterwards, and analyzing it twice
 *    there would apply side effects (`++$i`, `$a[] = $x`) twice.
 *  - Only a Closure/ArrowFunction literal slot gets the typed callable. A passed-through callable
 *    may declare fewer params, which Psalm rejects against a 2-param callable, and a first-class
 *    callable has no declared-type escape for a subclass receiver. A literal whose first param is
 *    variadic is skipped too: Psalm fills every element from the receiver slot alone.
 *  - A declared receiver param type (native or docblock) is trusted as written: at runtime `$this`
 *    may be any subclass or implementer of the host (a custom builder Psalm cannot see, an
 *    intersection with an interface), so only an untyped receiver param gets the computed type.
 *    Accepted loss: a declared type unrelated to the host (`Query\Builder` on an Eloquent Builder)
 *    is no longer reported; Psalm never reported it against the stub either.
 *  - A declared value param type wins when it already contains the computed slot type (Psalm
 *    would otherwise narrow a defensive `?int $x` to `int` and report its null check).
 *    Declared types are memoized per literal node on first sight because Psalm overwrites
 *    closure storage param types with inferred ones, and loops re-analyze the same node.
 *  - A `void` Closure value is `null` at runtime, so it reconciles as `null`.
 *
 * Declines (Psalm's stub signature stands) on: unpacked args, a value arg that is not the first
 * arg (reordered named args: earlier args' side effects would not be seen by the pre-analysis),
 * a value that is mixed or may be an opaque Closure (callable, object, template, bare Closure:
 * Laravel would invoke it), a receiver that is not exactly the dispatched class (unions
 * re-analyze the closure per atomic, last one wins; relation `@mixin` forwarding passes the
 * underlying Builder at runtime). A slot keeps its stub callable when the literal declares a
 * late-bound `self`/`static`/`parent` type at any depth (`list<self>` too): closure storage keeps
 * it unexpanded and Psalm never matches it against the host type. It also keeps it when an
 * untyped literal param has a default, and on a dead branch (truthy of `null`, falsy of `true`)
 * instead of `never`, which would report NoValue.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1624
 */
final class ConditionableCallbackParamsHandler implements
    AfterCodebasePopulatedInterface,
    BeforeExpressionAnalysisInterface
{
    /**
     * when()/unless() calls awaiting their params lookup, keyed by the call's first Arg (the only
     * call-identifying object the provider event exposes). Weakly keyed: entries die with the AST.
     *
     * @psalm-var \WeakMap<Arg, MethodCall|NullsafeMethodCall>|null
     */
    private static ?\WeakMap $calls = null;

    /**
     * Hosts already registered, so a repeated population does not stack closures. Psalm's
     * MethodParamsProvider constructor clears its handlers per Codebase, so this set must be
     * cleared with it ({@see reset()}) or a second Codebase in one process gets no registration.
     *
     * @var array<lowercase-string, true>
     */
    private static array $registered = [];

    /**
     * Declared closure-literal param types by literal node (null: decline the slot).
     *
     * @psalm-var \WeakMap<Closure|ArrowFunction, array<int, Union>|null>|null
     */
    private static ?\WeakMap $declaredTypes = null;

    public static function reset(): void
    {
        self::$calls = null;
        self::$registered = [];
        self::$declaredTypes = null;
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $key = \strtolower($storage->name);

            if ($storage->is_trait
                || isset(self::$registered[$key])
                || !self::declaredByConditionable($storage->declaring_method_ids['when'] ?? null)
                || !self::declaredByConditionable($storage->declaring_method_ids['unless'] ?? null)
            ) {
                continue;
            }

            self::$registered[$key] = true;
            $codebase->methods->params_provider->registerClosure($storage->name, self::getMethodParams(...));
        }
    }

    /** @psalm-pure */
    private static function declaredByConditionable(?MethodIdentifier $declaring): bool
    {
        return $declaring instanceof MethodIdentifier
            && \strtolower($declaring->fq_class_name) === \strtolower(Conditionable::class);
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if ((!$expr instanceof MethodCall && !$expr instanceof NullsafeMethodCall)
            || !$expr->name instanceof Identifier
            || $expr->isFirstClassCallable()
        ) {
            return null;
        }

        $method = $expr->name->toLowerString();
        $args = $expr->getArgs();

        if (($method === 'when' || $method === 'unless') && \count($args) >= 2) {
            (self::$calls ??= self::newCallMap())->offsetSet($args[0], $expr);
        }

        return null;
    }

    /** @return list<FunctionLikeParameter>|null */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $method = $event->getMethodNameLowercase();
        if ($method !== 'when' && $method !== 'unless') {
            return null;
        }

        $args = $event->getCallArgs();
        if ($args === null || \count($args) < 2) {
            return null;
        }

        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }
        }

        $literals = [
            'callback' => self::closureLiteral(self::findArg($args, 'callback', 1)),
            'default' => self::closureLiteral(self::findArg($args, 'default', 2)),
        ];
        if ($literals['callback'] === null && $literals['default'] === null) {
            return null;
        }

        // A value arg preceded by another arg (reordered named args) would be pre-analyzed before
        // that arg's side effects; only a leading value arg reads the state it is evaluated in.
        $valueArg = self::findArg($args, 'value', 0);
        if ($valueArg instanceof \PhpParser\Node\Arg && $valueArg !== $args[0]) {
            return null;
        }

        $source = $event->getStatementsSource();
        $context = $event->getContext();
        // A stash miss (static or forwarded call) leaves no receiver node to read.
        $call = self::$calls[$args[0]] ?? null;
        if (!$source instanceof StatementsAnalyzer || !$context instanceof Context || $call === null) {
            return null;
        }

        $receiver = self::receiver($source, $call, $event->getFqClasslikeName());
        if (!$receiver instanceof TNamedObject) {
            return null;
        }

        $value = self::valueType($source, $context, $valueArg);
        if (!$value instanceof Union) {
            return null;
        }

        $truthy = self::reconcile(new Truthy(), $value, $source);
        $falsy = self::reconcile(new Falsy(), $value, $source);
        $slotValues = $method === 'when'
            ? ['callback' => $truthy, 'default' => $falsy]
            : ['callback' => $falsy, 'default' => $truthy];

        $codebase = $source->getCodebase();

        try {
            $params = $codebase->methods->getStorage(new MethodIdentifier(Conditionable::class, $method))->params;
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return null;
        }

        $result = [];
        foreach ($params as $param) {
            $slotValue = $slotValues[$param->name] ?? null;
            $literal = $literals[$param->name] ?? null;
            $type = $slotValue instanceof Union && $literal !== null
                ? self::callbackType($source, $codebase, $receiver, $slotValue, $literal)
                : null;

            $result[] = $type instanceof Union ? $param->setType($type) : $param;
        }

        return $result;
    }

    /**
     * The slot's Closure/ArrowFunction literal, unless its first param is variadic.
     *
     * @psalm-mutation-free
     */
    private static function closureLiteral(?Arg $arg): Closure|ArrowFunction|null
    {
        $value = $arg?->value;
        if (!$value instanceof Closure && !$value instanceof ArrowFunction) {
            return null;
        }

        return isset($value->params[0]) && $value->params[0]->variadic ? null : $value;
    }

    /**
     * The receiver as the callback will see it: exactly one object atomic of the dispatched class
     * (null dropped for `?->`), else null. `$this` in a trait is `Host&static`; a final host has
     * no subclass, and Psalm reads the expected param as the plain host, so `&static` is dropped.
     */
    private static function receiver(
        StatementsAnalyzer $source,
        MethodCall|NullsafeMethodCall $call,
        string $dispatchedClass,
    ): ?TNamedObject {
        $type = $source->getNodeTypeProvider()->getType($call->var);
        if (!$type instanceof Union) {
            return null;
        }

        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNull) {
                $atomics[] = $atomic;
            }
        }

        if (\count($atomics) !== 1) {
            return null;
        }

        $atomic = $atomics[0];
        if (!$atomic instanceof TNamedObject || \strtolower($atomic->value) !== \strtolower($dispatchedClass)) {
            return null;
        }

        if (!$atomic->is_static) {
            return $atomic;
        }

        try {
            $final = $source->getCodebase()->classlike_storage_provider->get($dispatchedClass)->final;
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        return $final ? $atomic->setIsStatic(false) : $atomic;
    }

    /**
     * The value Laravel tests for truthiness: a Closure `$value` is invoked first, so its return
     * type stands in (`void` returns null; `never` leaves both branches dead). Absent arg means
     * the `null` default; a mixed part or a possibly-Closure atomic with no known return type
     * declines.
     */
    private static function valueType(StatementsAnalyzer $source, Context $context, ?Arg $arg): ?Union
    {
        if (!$arg instanceof Arg) {
            return Type::getNull();
        }

        $nodeTypes = $source->getNodeTypeProvider();
        $type = $nodeTypes->getType($arg->value);

        if (!$type instanceof Union) {
            $probe = clone $context;
            $probe->inside_call = true;
            ExpressionAnalyzer::analyze($source, $arg->value, $probe);
            $type = $nodeTypes->getType($arg->value);
        }

        if (!$type instanceof Union) {
            return null;
        }

        $resolved = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TClosure && self::mayBeClosure($atomic)) {
                return null;
            }

            $parts = $atomic instanceof TClosure ? $atomic->return_type?->getAtomicTypes() : [$atomic];
            if ($parts === null) {
                return null;
            }

            foreach ($parts as $part) {
                if ($part instanceof TMixed) {
                    return null;
                }

                $resolved[] = $part instanceof TVoid ? new TNull() : $part;
            }
        }

        return new Union($resolved);
    }

    /**
     * Whether a Closure instance could hide behind this atomic. Closure is final and implements
     * no interface, so among named objects only `Closure` itself can (Psalm normally types it as a
     * TClosure, handled by the caller; this catches a bare named-object form).
     *
     * @psalm-pure
     */
    private static function mayBeClosure(Atomic $atomic): bool
    {
        return $atomic instanceof TCallable
            || $atomic instanceof TObject
            || $atomic instanceof TTemplateParam
            || ($atomic instanceof TNamedObject && \strtolower($atomic->value) === 'closure');
    }

    /** The branch's value type, or null when the branch is dead (no value reaches it). */
    private static function reconcile(Assertion $assertion, Union $value, StatementsAnalyzer $source): ?Union
    {
        $failed = Reconciler::RECONCILIATION_OK;
        $type = AssertionReconciler::reconcile($assertion, $value, null, $source, false, [], null, [], $failed);

        return $failed === Reconciler::RECONCILIATION_EMPTY || $type->isNever() ? null : $type;
    }

    /**
     * `callable(<receiver>, <value>): mixed|null`, each param preferring the literal's declared
     * type; null when the literal's slot must keep the stub callable. An untyped param with a
     * default declines: Psalm would infer it from the computed type alone and flag a defensive
     * check of the default (`$v = null` then `if ($v === null)`).
     */
    private static function callbackType(
        StatementsAnalyzer $source,
        Codebase $codebase,
        TNamedObject $receiver,
        Union $value,
        Closure|ArrowFunction $literal,
    ): ?Union {
        $declared = self::declaredParamTypes($source, $codebase, $literal);
        if ($declared === null) {
            return null;
        }

        $params = [];
        foreach (['instance' => new Union([$receiver]), 'value' => $value] as $name => $computed) {
            $offset = \count($params);
            $literalParam = $literal->params[$offset] ?? null;
            if ($literalParam !== null && $literalParam->default !== null && !isset($declared[$offset])) {
                return null;
            }

            $declaredType = $declared[$offset] ?? null;
            $keep = $declaredType instanceof Union
                && ($name === 'instance' || UnionTypeComparator::isContainedBy($codebase, $computed, $declaredType));
            $type = $keep ? $declaredType : $computed;

            $params[] = new FunctionLikeParameter($name, false, $type, $type, is_optional: false);
        }

        return new Union([new TCallable($params, Type::getMixed()), new TNull()]);
    }

    /**
     * Native or docblock param types a closure literal declares, by offset, or null to decline
     * the slot: a late-bound type (`self`/`static`/`parent`, at any depth) is declared, or the
     * closure storage is missing. Memoized on first sight: ArgumentsAnalyzer later overwrites the
     * storage param type with the inferred one (marking it `type_inferred`), so a loop's second
     * pass would no longer see the declaration.
     *
     * @return array<int, Union>|null
     */
    private static function declaredParamTypes(
        StatementsAnalyzer $source,
        Codebase $codebase,
        Closure|ArrowFunction $literal,
    ): ?array {
        if (!self::$declaredTypes instanceof \WeakMap) {
            /** @psalm-var \WeakMap<Closure|ArrowFunction, array<int, Union>|null> $fresh */
            $fresh = new \WeakMap();
            self::$declaredTypes = $fresh;
        }

        $memo = self::$declaredTypes;
        if ($memo->offsetExists($literal)) {
            return $memo->offsetGet($literal);
        }

        $types = self::readDeclaredParamTypes($source, $codebase, $literal);
        $memo->offsetSet($literal, $types);

        return $types;
    }

    /** @return array<int, Union>|null */
    private static function readDeclaredParamTypes(
        StatementsAnalyzer $source,
        Codebase $codebase,
        Closure|ArrowFunction $literal,
    ): ?array {
        // Same id scheme as Psalm's closure-param inference (ArgumentsAnalyzer).
        $closureId = \strtolower($source->getFilePath())
            . ':' . $literal->getLine()
            . ':' . (int) $literal->getAttribute('startFilePos')
            . ':-:closure';

        try {
            $storage = $codebase->getClosureStorage($source->getFilePath(), $closureId);
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return null;
        }

        $types = [];
        foreach ($storage->params as $offset => $param) {
            if (!$param->type instanceof Union || $param->type_inferred) {
                continue;
            }

            if (self::containsLateBound($param->type)) {
                return null;
            }

            $types[$offset] = $param->type;
        }

        return $types;
    }

    /**
     * Whether `self`/`static`/`parent` appears anywhere in the type, generics included. Closure
     * storage keeps these unexpanded, and Psalm never matches them against the host type.
     */
    private static function containsLateBound(Union $type): bool
    {
        $visitor = new class extends TypeVisitor {
            /** @psalm-pure */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                $lateBound = $type instanceof TNamedObject
                    && \in_array(\strtolower($type->value), ['self', 'static', 'parent'], true);

                return $lateBound ? self::STOP_TRAVERSAL : null;
            }
        };

        // traverse() returns false exactly when enterNode() stopped the traversal.
        return !$visitor->traverse($type);
    }

    /**
     * The arg bound to a parameter: by name when named, else by position among the leading
     * positional args.
     *
     * @param list<Arg> $args
     * @psalm-mutation-free
     */
    private static function findArg(array $args, string $name, int $position): ?Arg
    {
        foreach ($args as $offset => $arg) {
            if ($arg->name instanceof Identifier ? $arg->name->name === $name : $offset === $position) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * @return \WeakMap<Arg, MethodCall|NullsafeMethodCall>
     * @psalm-pure
     */
    private static function newCallMap(): \WeakMap
    {
        /** @psalm-var \WeakMap<Arg, MethodCall|NullsafeMethodCall> $map */
        $map = new \WeakMap();

        return $map;
    }
}
