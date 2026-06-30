--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\App;

// Laravel's `App` facade declares make()/makeWith()/get() as `object|mixed` (collapses to mixed),
// polluting e.g. a Nova resource's `actions(): list<Action>`. The plugin narrows a class-string
// argument to the resolved class, matching app() / resolve() / $container->make().

final class SomeAction {}
interface SomeContract {}

function make_narrows_class_string(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = App::make(SomeAction::class);

    return $a;
}

function make_with_narrows_class_string(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = App::makeWith(SomeAction::class, ['label' => 'x']);

    return $a;
}

function get_narrows_class_string(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = App::get(SomeAction::class);

    return $a;
}

// `make(Interface::class)` resolves to the bound implementation, which is-a Interface.
function make_narrows_interface(): SomeContract
{
    /** @psalm-check-type-exact $a = SomeContract */
    $a = App::make(SomeContract::class);

    return $a;
}

// The global `\App` alias resolves identically to the canonical facade.
function make_via_global_alias(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = \App::make(SomeAction::class);

    return $a;
}

// Named-argument calls must keep narrowing: the params provider names make()'s first argument
// `abstract` precisely so the named binding resolves.
function make_named_argument_narrows(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = App::make(abstract: SomeAction::class);

    return $a;
}

// PSR-11 `get()` names its argument `id`, not `abstract`; the params provider must mirror that
// so `App::get(id: ...)` does not emit a false-positive InvalidNamedArgument.
function get_named_argument_narrows(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = App::get(id: SomeAction::class);

    return $a;
}

// Named arguments can be reordered; the abstract is matched by name, not by position.
function make_reordered_named_arguments_narrows(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = App::make(parameters: [], abstract: SomeAction::class);

    return $a;
}

function make_with_reordered_named_arguments_narrows(): SomeAction
{
    /** @psalm-check-type-exact $a = SomeAction */
    $a = App::makeWith(parameters: [], abstract: SomeAction::class);

    return $a;
}

abstract class AppMakeFactory
{
    // `static::class` preserves late static binding, so a `: static` factory type-checks AND
    // the exact intersection type is pinned (a regression to bare `static` or `mixed` is caught).
    public static function makeViaStatic(): static
    {
        $instance = App::make(static::class);
        /** @psalm-check-type-exact $instance = AppMakeFactory&static */

        return $instance;
    }

    // `self::class` is the lexical class, with no `&static` intersection.
    public static function makeViaSelf(): self
    {
        $instance = App::make(self::class);
        /** @psalm-check-type-exact $instance = AppMakeFactory */

        return $instance;
    }
}

// Inside a trait, self::class/static::class resolve to the consuming class at runtime, but the
// analyser sees the trait here, so narrowing is skipped (deferred to mixed) rather than inferring
// the non-instantiable trait type.
trait AppMakeInTrait
{
    public function makeViaSelfInTrait(): void
    {
        $resolved = App::make(self::class);
        /** @psalm-check-type-exact $resolved = object|mixed */
        $resolved;
    }
}

final class AppMakeTraitConsumer
{
    use AppMakeInTrait;
}

// Plain-string abstracts ('view', 'config', container aliases) are NOT class-strings, so they
// keep Laravel's declared `object|mixed` — the resolver must not over-narrow them.
function make_plain_string_is_not_narrowed(): void
{
    $service = App::make('config');
    /** @psalm-check-type-exact $service = object|mixed */
    $service;
}

// The params provider declares `abstract` as required, so a zero-argument call is reported
// (and analysis must not abort with "Cannot get method params").
function make_requires_an_abstract(): void
{
    App::make();
}

// get() takes a single argument; a stray second arg is reported (it must not inherit make()'s
// optional `parameters`).
function get_rejects_a_second_argument(): void
{
    App::get(SomeAction::class, []);
}

?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $resolved is being assigned to
UnusedVariable on line %d: $resolved is never referenced or the value is not used
MixedAssignment on line %d: Unable to determine the type that $service is being assigned to
UnusedVariable on line %d: $service is never referenced or the value is not used
TooFewArguments on line %d: Too few arguments for Illuminate\Support\Facades\App::make - expecting abstract to be passed
TooManyArguments on line %d: Too many arguments for Illuminate\Support\Facades\App::get - expecting 1 but saw 2
