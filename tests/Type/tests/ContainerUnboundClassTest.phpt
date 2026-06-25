--FILE--
<?php declare(strict_types=1);

// Regression for #757 (subset of umbrella #766): `app(Foo::class)` /
// `resolve(Foo::class)` / `make(Foo::class)` previously returned `mixed` whenever
// the plugin's booted app could not `make()` the abstract — e.g. the class is
// unbound and its constructor has unresolvable (scalar / provider-supplied)
// dependencies, or a non-public constructor. The literal class-string branch now
// falls back to a named object, mirroring Laravel's runtime: in a real app the
// owning provider is loaded, so `app(Foo::class)` returns a Foo.
//
// NB: the fixture must be a *real autoloadable* class. `ContainerResolver` uses
// PHP's `class_exists()` (the analysed project's autoloader is registered with
// Psalm), so the fallback only fires for classes loadable in the Psalm process —
// which every genuine package class (the issue's Spatie\Backup\Config\Config) is.
// `Illuminate\Validation\Rules\Enum` is unbound in the booted app and its
// constructor requires a scalar `$type`, so `make()` throws BindingResolutionException.

use Illuminate\Validation\Rules\Enum;

function appHelperResolvesUnboundClass(): Enum
{
    $rule = app(Enum::class);
    /** @psalm-check-type-exact $rule = Illuminate\Validation\Rules\Enum */
    return $rule;
}

function resolveHelperResolvesUnboundClass(): Enum
{
    return resolve(Enum::class);
}

function containerMakeResolvesUnboundClass(): Enum
{
    return app()->make(Enum::class);
}
?>
--EXPECTF--
