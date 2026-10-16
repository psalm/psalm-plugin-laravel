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
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Types the call-site receiver into the callable params of the Collection / LazyCollection
 * methods that hand `$this` to a closure: `tap()`, `pipe()`, `whenEmpty()` / `whenNotEmpty()` /
 * `unlessEmpty()` / `unlessNotEmpty()` (callback and `$default`: param 0, followed by the
 * `$value` Laravel's `when()` passes on: `true` for the callback, `false` for `$default`) and
 * `Collection::chunkWhile()` (callback: param 2, the chunk). The chunk is a plain
 * `Illuminate\Support\Collection` of the receiver's key/value types, never the receiver class:
 * Laravel converts the chunks to the receiver class only after the callback ran. A stub callable cannot spell the
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
 * `LazyCollection::chunkWhile()` is left alone: it passes a plain `Collection` chunk too, which
 * its own docblock already declares. A chunk whose key/value types cannot be read exactly off the
 * receiver (a subclass forwarding them through a non-trivial type) keeps the stub callable.
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

        $slotType = $method === 'chunkwhile'
            ? self::chunkType($codebase, $receiver)
            : new Union([$receiver]);
        if (!$slotType instanceof Union) {
            return null;
        }

        $result = [];
        $changed = false;
        foreach ($params as $position => $param) {
            $literal = CallbackCallSite::closureLiteral(ArgUtil::boundTo($args, $param->name, $position));
            $type = isset($slots[$param->name]) && $literal !== null && $param->type instanceof Union
                ? self::withSlot($source, $param, $slots[$param->name], $slotType, $literal)
                : null;

            $changed = $changed || $type instanceof Union;
            $result[] = $type instanceof Union ? $param->setType($type) : $param;
        }

        return $changed ? $result : null;
    }

    /**
     * The param type with `$slotType` put into its callable's slot, or null to keep it. A stub
     * callable without params (`callable $callback`) gets the receiver and the `$value` Laravel's
     * `when()` forwards: `true` for `$callback`, `false` for `$default`.
     */
    private static function withSlot(
        StatementsAnalyzer $source,
        FunctionLikeParameter $param,
        int $offset,
        Union $slotType,
        Closure|ArrowFunction $literal,
    ): ?Union {
        $type = $param->type;
        $literalParam = $literal->params[$offset] ?? null;
        if (!$type instanceof Union || $literalParam === null || $literalParam->variadic || $literalParam->default !== null) {
            return null;
        }

        $declared = CallbackCallSite::declaredParamTypes($source, $source->getCodebase(), $literal);
        if ($declared === null || isset($declared[$offset])) {
            return null;
        }

        $atomics = [];
        $replaced = false;
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TCallable) {
                $callableParams = $atomic->params ?? [];
                if ($callableParams === [] && $offset === 0) {
                    $value = $param->name === 'default' ? Type::getFalse() : Type::getTrue();
                    $callableParams = [
                        new FunctionLikeParameter('instance', false, is_optional: false),
                        new FunctionLikeParameter('value', false, $value, $value, is_optional: false),
                    ];
                }

                if (isset($callableParams[$offset])) {
                    $callableParams[$offset] = new FunctionLikeParameter(
                        $callableParams[$offset]->name,
                        false,
                        $slotType,
                        $slotType,
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

    /**
     * `Collection<TKey, TValue>` in the receiver's terms, or null when they cannot be read exactly.
     *
     * @psalm-mutation-free
     */
    private static function chunkType(Codebase $codebase, TNamedObject $receiver): ?Union
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($receiver->value);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }

        $key = self::collectionTemplate($storage, $receiver, 'TKey');
        $value = self::collectionTemplate($storage, $receiver, 'TValue');

        return $key instanceof Union && $value instanceof Union
            ? new Union([new TGenericObject(Collection::class, [$key, $value])])
            : null;
    }

    /**
     * What `Collection`'s `$template` is for this receiver: the receiver's own type param, or the
     * concrete type a subclass binds it to. `template_extended_params` states each ancestor's
     * template in its direct child's terms, so a forwarded template is followed up to the receiver.
     *
     * @psalm-mutation-free
     */
    private static function collectionTemplate(ClassLikeStorage $storage, TNamedObject $receiver, string $template): ?Union
    {
        $class = Collection::class;
        for ($hops = 0; $hops < 8; ++$hops) {
            if (\strcasecmp($class, $storage->name) === 0) {
                $position = \array_search($template, \array_keys($storage->template_types ?? []), true);

                return $position !== false && $receiver instanceof TGenericObject
                    ? ($receiver->type_params[$position] ?? null)
                    : null;
            }

            $value = $storage->template_extended_params[$class][$template] ?? null;
            if (!$value instanceof Union) {
                return null;
            }

            $forwarded = $value->isSingle() ? $value->getSingleAtomic() : null;
            if (!$forwarded instanceof TTemplateParam) {
                return $value->hasTemplate() ? null : $value;
            }

            $class = $forwarded->defining_class;
            $template = $forwarded->param_name;
        }

        return null;
    }
}
