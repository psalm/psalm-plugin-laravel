--FILE--
<?php declare(strict_types=1);

// Regression for the false-positive UndefinedMethod on `$app->when(Foo::class)->needs(...)`.
//
// ContainerHandler is a MethodReturnTypeProvider registered per-class, so Psalm offers it
// EVERY method on the container — not only the resolving ones. It used to feed the first
// `Foo::class` argument to ContainerResolver for any method, so `$app->when(Foo::class)`
// (a contextual-binding builder) collapsed to `Foo`, and the chained `->needs()` reported
// `Foo::needs does not exist`. The provider is now gated to the resolution methods
// (make / makeWith / get) that actually carry the `class-string<T> -> T` contract; every
// other container method keeps its real return type. Regression introduced by #1075, which
// added the container contracts to ContainerHandler::getClassLikeNames().
//
// NB: the `when()` assertions below lock in the correct behaviour but do not fail in this
// harness without the fix — the un-gated provider only mis-fired for `when()` under a full
// real-app scan (the `$this->app` interface receiver), which the minimal psalm-tester scan
// does not reproduce. The end-to-end proof lives in the PR description; the concrete
// `app()->make()` guard at the bottom is the co-located check that DOES fire here and would
// break if the gate ever dropped make() narrowing.

use Illuminate\Contracts\Container\ContextualBindingBuilder;
use Illuminate\Contracts\Foundation\Application;

final class WhenService {}
interface WhenContract {}

// when() keeps its real ContextualBindingBuilder return on the idiomatic `$this->app`
// (Application contract) receiver...
function when_returns_contextual_binding_builder(Application $app): ContextualBindingBuilder
{
    $builder = $app->when(WhenService::class);
    /** @psalm-check-type-exact $builder = ContextualBindingBuilder */
    return $builder;
}

// ...so the full contextual-binding chain from the bug report analyses without UndefinedMethod.
function when_needs_give_chain_resolves(Application $app): void
{
    $app->when(WhenService::class)
        ->needs(WhenContract::class)
        ->give(static fn(): WhenService => new WhenService());
}

// Guard that the gate keeps make() narrowing: on a concrete container receiver (the surface
// that fires in this harness) a class-string argument still resolves to the named class.
function make_still_narrows_the_resolved_class(): WhenService
{
    $service = app()->make(WhenService::class);
    /** @psalm-check-type-exact $service = WhenService */
    return $service;
}
?>
--EXPECTF--
