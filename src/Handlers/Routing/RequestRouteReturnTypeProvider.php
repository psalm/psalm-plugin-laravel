<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Routing;

use Illuminate\Http\Request;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Narrows {@see Request::route()} calls when the parameter name is a literal
 * string and {@see RouteParameterRegistry} has information about it.
 *
 * Two narrowing modes:
 *
 *  - Binding known (Route::bind / Route::model with a Model subclass) → the
 *    return is `BoundClass|null` for the resolved subclass, replacing the
 *    stub's `TDefault|string|Model|BackedEnum|null`. Eliminates
 *    `InvalidPropertyFetch` on `$request->route('genre')->id` and gives the
 *    caller the exact bound type to chain Eloquent attribute access on.
 *    Issues #801, #803.
 *
 *  - No binding but a safe regex constraint is registered for the name →
 *    the return narrows to `string|null`. The narrowing itself is what
 *    causes Psalm to drop the stub's `@psalm-taint-source` annotation, which
 *    is the whole point: when the route's regex defeats the relevant sinks
 *    the value should not propagate as tainted input. The companion
 *    {@see RequestRouteTaintHandler} keeps the source removed for sinks the
 *    constraint provably defeats and partially re-adds it for sinks that
 *    accept alphanumeric identifiers (callable/include/eval/extract/shell).
 *    Issue #849.
 *
 * Calls without a literal first argument and calls outside the registry's
 * coverage are left alone, so the stub's `($param is null ? Route :
 * TDefault|string|Model|BackedEnum|null)` falls through with its taint source
 * intact.
 */
final class RequestRouteReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // Request::route() is declared on Request; FormRequest and Symfony's
        // Request subclasses inherit it, so a single hook covers every caller
        // shape we care about. Psalm's MethodCallReturnTypeFetcher resolves
        // inherited calls to the declaring class, which dispatches here.
        return [Request::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'route') {
            return null;
        }

        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $name = RouteParameterArg::extractLiteralName($event->getCallArgs(), $source);

        if ($name === null) {
            return null;
        }

        $registry = RouteParameterRegistry::instance();
        $boundModel = $registry->getBoundModel($name);

        if ($boundModel !== null) {
            return new Union([
                new TNamedObject($boundModel),
                new TNull(),
            ]);
        }

        if ($registry->hasSafeConstraint($name)) {
            // Strictly narrower than the stub (drops TDefault, Model, and
            // BackedEnum). Acceptable: the safe-constraint branch targets
            // the URL-segment-as-string pattern, where callers rarely chain
            // model-shaped access on the result. Returning a non-null type
            // is what causes Psalm to drop the stub's @psalm-taint-source —
            // the actual sink-level safety lives in RequestRouteTaintHandler.
            return Type::combineUnionTypes(Type::getString(), Type::getNull());
        }

        return null;
    }
}
