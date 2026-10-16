<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Traits\EnumeratesValues;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Types the call-site receiver into the callable params of the Collection / LazyCollection
 * methods that hand `$this` to a closure: `tap()`, `pipe()`, `whenEmpty()` / `whenNotEmpty()` /
 * `unlessEmpty()` / `unlessNotEmpty()` (callback and `$default`: param 0) and
 * `Collection::chunkWhile()` (callback: param 2, the chunk). A stub callable cannot spell the
 * receiver: `callable($this)` expands to a class-name-only type that rejects an untyped closure
 * param (`static`), and widening it to `callable`/`mixed` degrades the param to `mixed`
 * (docs/contributing/decisions.md). Only the slot is replaced, so `pipe()` keeps inferring
 * its template return type from the closure.
 *
 * Mechanics are those of {@see ConditionableCallbackParamsHandler}: params providers dispatch on
 * the called class, so a closure is registered per concrete host after population, and the
 * receiver is read from the call's left-hand side through {@see CallbackCallSite}. The receiver
 * comes from that expression's type, never from the declared templates, so a subclass that
 * fixes, reorders or renames them is typed correctly.
 *
 * A slot keeps the stub's callable (the pre-existing behavior) when:
 *  - the literal's param at the slot is typed (trusted as written, and a wrong-shape type such as
 *    `tap(fn (User $x))` stays reported), has a default (Psalm would report a defensive check of
 *    it), is variadic or is missing; or the literal declares a late-bound type in any param;
 *  - the argument is not a Closure/ArrowFunction literal (string, array or first-class callable);
 *  - the call has unpacked args, or the receiver is not exactly one object of the dispatched class
 *    (unions would re-analyze the closure per atomic, last one wins).
 *
 * `LazyCollection::chunkWhile()` is left alone: Laravel passes it a plain `Collection` chunk,
 * which its own docblock already declares.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1900
 */
final class CollectionCallbackParamsHandler implements
    AfterCodebasePopulatedInterface,
    BeforeExpressionAnalysisInterface
{
    /**
     * Lowercased method => [class declaring it (the only signature the slots are valid for),
     * callable param name => offset of the callable param that receives the receiver].
     */
    private const METHODS = [
        'tap' => [EnumeratesValues::class, ['callback' => 0]],
        'pipe' => [EnumeratesValues::class, ['callback' => 0]],
        'whenempty' => [EnumeratesValues::class, ['callback' => 0, 'default' => 0]],
        'whennotempty' => [EnumeratesValues::class, ['callback' => 0, 'default' => 0]],
        'unlessempty' => [EnumeratesValues::class, ['callback' => 0, 'default' => 0]],
        'unlessnotempty' => [EnumeratesValues::class, ['callback' => 0, 'default' => 0]],
        'chunkwhile' => [Collection::class, ['callback' => 2]],
    ];

    /**
     * Hosts already registered, so a repeated population does not stack closures. Psalm's
     * MethodParamsProvider constructor clears its handlers per Codebase, so this set must be
     * cleared with it ({@see reset()}).
     *
     * @var array<lowercase-string, true>
     */
    private static array $registered = [];

    public static function reset(): void
    {
        self::$registered = [];
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $key = \strtolower($storage->name);

            if ($storage->is_trait || $storage->is_interface || isset(self::$registered[$key])) {
                continue;
            }

            foreach (self::METHODS as $method => [$declaringClass]) {
                if (self::isDeclaredBy($storage->declaring_method_ids[$method] ?? null, $declaringClass)) {
                    self::$registered[$key] = true;
                    $codebase->methods->params_provider->registerClosure($storage->name, self::getMethodParams(...));

                    break;
                }
            }
        }
    }

    /** @psalm-pure */
    private static function isDeclaredBy(?MethodIdentifier $declaring, string $class): bool
    {
        return $declaring instanceof MethodIdentifier
            && \strtolower($declaring->fq_class_name) === \strtolower($class);
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall)
            && $expr->name instanceof Identifier
            && !$expr->isFirstClassCallable()
            && isset(self::METHODS[$expr->name->toLowerString()])
        ) {
            CallbackCallSite::remember($expr);
        }

        return null;
    }

    /** @return list<FunctionLikeParameter>|null */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $method = $event->getMethodNameLowercase();
        $args = $event->getCallArgs();
        if (!isset(self::METHODS[$method]) || $args === null || $args === []) {
            return null;
        }

        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }
        }

        $source = $event->getStatementsSource();
        // A stash miss (static or forwarded call) leaves no receiver node to read.
        $call = CallbackCallSite::callOf($args[0]);
        if (!$source instanceof StatementsAnalyzer || $call === null) {
            return null;
        }

        $dispatched = $event->getFqClasslikeName();
        [$declaringClass, $slots] = self::METHODS[$method];
        $codebase = $source->getCodebase();

        try {
            $declaring = $codebase->classlike_storage_provider->get($dispatched)->declaring_method_ids[$method] ?? null;
            if (!$declaring instanceof MethodIdentifier || !self::isDeclaredBy($declaring, $declaringClass)) {
                return null;
            }

            $params = $codebase->methods->getStorage($declaring)->params;
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            return null;
        }

        $receiver = CallbackCallSite::receiver($source, $call, $dispatched);
        if (!$receiver instanceof TNamedObject) {
            return null;
        }

        $result = [];
        $changed = false;
        foreach ($params as $position => $param) {
            $literal = CallbackCallSite::closureLiteral(ArgUtil::boundTo($args, $param->name, $position));
            $type = isset($slots[$param->name]) && $literal !== null && $param->type instanceof Union
                ? self::withReceiverSlot($source, $param->type, $slots[$param->name], $receiver, $literal)
                : null;

            $changed = $changed || $type instanceof Union;
            $result[] = $type instanceof Union ? $param->setType($type) : $param;
        }

        return $changed ? $result : null;
    }

    /**
     * The param type with the receiver put into its callable's slot, or null to keep it. A stub
     * callable without params (`callable $callback`) gets a single receiver param.
     */
    private static function withReceiverSlot(
        StatementsAnalyzer $source,
        Union $type,
        int $offset,
        TNamedObject $receiver,
        Closure|ArrowFunction $literal,
    ): ?Union {
        $literalParam = $literal->params[$offset] ?? null;
        if ($literalParam === null || $literalParam->variadic || $literalParam->default !== null) {
            return null;
        }

        $declared = CallbackCallSite::declaredParamTypes($source, $source->getCodebase(), $literal);
        if ($declared === null || isset($declared[$offset])) {
            return null;
        }

        $computed = new Union([$receiver]);
        $atomics = [];
        $replaced = false;
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TCallable) {
                $callableParams = $atomic->params ?? [];
                if ($callableParams === [] && $offset === 0) {
                    $callableParams = [new FunctionLikeParameter('instance', false, is_optional: false)];
                }

                if (isset($callableParams[$offset])) {
                    $callableParams[$offset] = new FunctionLikeParameter(
                        $callableParams[$offset]->name,
                        false,
                        $computed,
                        $computed,
                        is_optional: false,
                    );
                    $atomic = $atomic->replace($callableParams, $atomic->return_type);
                    $replaced = true;
                }
            }

            $atomics[] = $atomic;
        }

        return $replaced ? new Union($atomics) : null;
    }
}
