<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\HigherOrderTapProxy;
use Illuminate\Support\Traits\Tappable;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Types the no-argument higher-order form of {@see Tappable::tap()} as
 * `HigherOrderTapProxy<static>`, the precise return the stub cannot express.
 *
 * The stub declares `@return $this`: correct for `tap($callback)` (Laravel runs the callback
 * and returns the instance), but only an approximation for the no-arg `tap()`, which actually
 * returns a {@see HigherOrderTapProxy} wrapping the instance. A conditional return type in the
 * stub does not discriminate when it overrides a reflected trait method (Psalm collapses every
 * call to the null branch), so this handler supplies the proxy for the no-callback case and
 * defers to the stub's `$this` otherwise.
 *
 * Registered on the `Tappable` trait: Psalm dispatches a method return-type provider on the
 * declaring class, which for a trait method is the trait, so one registration covers every host
 * (Stringable, Http\Client\Response, Uri, Mailable, Router, the paginators, …). Eloquent
 * Models and query builders are intentionally NOT affected: their `tap()` comes from
 * `Illuminate\Database\Concerns\BuildsQueries` (a required callback, returns the builder), a
 * different declaring class this handler never sees.
 *
 * The precise receiver type (with template params) is read from the call's left-hand side via
 * the node type provider. The proxy is generic ({@see HigherOrderTapProxy}), so a proxied call
 * threads the target type through `__call` without any further handler.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1110
 */
final class TappableTapHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // The declaring class of a trait method is the trait, and Psalm dispatches on it,
        // so this single entry covers every class that uses Tappable.
        return [Tappable::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'tap') {
            return null;
        }

        $source = $event->getSource();
        if (! $source instanceof StatementsAnalyzer) {
            return null;
        }

        $stmt = $event->getStmt();
        if (! $stmt instanceof MethodCall) {
            return null;
        }

        // A real callback → tap() returns the instance ($this); the stub's `@return $this` is
        // correct, so defer. Only the no-callback (or explicit-null) form yields the proxy.
        $callbackIsNull = self::callbackIsNull($source, $event->getCallArgs());
        if ($callbackIsNull === false) {
            return null;
        }

        $receiver = $source->getNodeTypeProvider()->getType($stmt->var);
        if (! $receiver instanceof Union) {
            return null;
        }

        // Strip `static` from the target before embedding it as the proxy's template argument.
        // A literal `static` atomic inside `HigherOrderTapProxy<...>` re-binds to the proxy when
        // read back through `__call(): TClass` (yielding a bogus `Target&HigherOrderTapProxy<...>`
        // intersection), because a handler-built concrete type is not tracked as a template
        // substitution the way the stub's `<static>` would be.
        $target = [];
        foreach ($receiver->getAtomicTypes() as $atomic) {
            $target[] = $atomic instanceof TNamedObject ? $atomic->setIsStatic(false) : $atomic;
        }

        $proxy = new TGenericObject(HigherOrderTapProxy::class, [new Union($target)]);

        // A nullable callable (`callable|null`) could go either way at runtime — union the proxy
        // with the instance so neither terminal nor chained use is mistyped.
        if ($callbackIsNull === null) {
            return new Union([$proxy, ...$target]);
        }

        return new Union([$proxy]);
    }

    /**
     * Whether the callback argument is null, in three states:
     *  - `true`  → no argument, or an argument typed exactly `null` (→ `HigherOrderTapProxy`);
     *  - `false` → an argument with a known, non-nullable type — the real-callback case, but also
     *              an untyped/`mixed` one (→ defer to the stub's `$this`);
     *  - `null`  → the type is undeterminable or nullable (→ union both branches).
     *
     * @param list<Arg> $args
     */
    private static function callbackIsNull(StatementsAnalyzer $source, array $args): ?bool
    {
        if ($args === []) {
            return true;
        }

        $type = $source->getNodeTypeProvider()->getType($args[0]->value);
        if (! $type instanceof Union) {
            return null;
        }

        if ($type->isNull()) {
            return true;
        }

        return $type->isNullable() ? null : false;
    }
}
