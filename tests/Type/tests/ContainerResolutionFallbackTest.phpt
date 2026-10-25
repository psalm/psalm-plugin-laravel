--FILE--
<?php declare(strict_types=1);

use Illuminate\Validation\Rules\Enum;

// The plugin rebinds 'Illuminate\Foundation\Bootstrap\HandleExceptions' to an anonymous class while
// booting its app, so make() returns an object whose class Psalm never scans (an anonymous class has
// no file Psalm can queue). The resolver must not name that `class@anonymous` object; it declines and
// falls back to the abstract, which is a loadable class.

function appHelperDeclinesAnonymousConcrete(): object
{
    $bootstrapper = app('Illuminate\Foundation\Bootstrap\HandleExceptions');
    /** @psalm-check-type-exact $bootstrapper = Illuminate\Foundation\Bootstrap\HandleExceptions */
    return $bootstrapper;
}

// `Monolog\Handler\StreamHandler` is unbound and its constructor needs a stream, so make() throws
// after reflecting (and thereby loading) the class. Named only by a string here, nothing makes Psalm
// scan it, so the abstract-itself fallback must decline: naming a class with no storage reports
// UndefinedClass at the use site even though PHP has it loaded.

function appHelperDeclinesLoadedButUnscannedClass(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $handler = app('Monolog\Handler\StreamHandler');
    /** @psalm-check-type-exact $handler = mixed */
    return $handler;
}

// Container keys are case-sensitive, but Psalm's class lookup is not. `schema` is no binding and
// make() cannot build it (the `Schema` facade alias class is abstract), so resolution fails and the
// abstract fallback must not name the `Schema` alias class under the lowercase spelling.

function appHelperDoesNotMatchAliasClassCaseInsensitively(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $schema = app('schema');
    /** @psalm-check-type-exact $schema = mixed */
    return $schema;
}

// A namespaced abstract keeps the lenient match and names the canonical class: PHP builds
// `Illuminate\Validation\Rules\enum` as `Enum` (unbound, constructor needs a scalar, so make()
// throws and the fallback applies).
function appHelperKeepsMisCasedNamespacedClass(): object
{
    $rule = app('Illuminate\Validation\Rules\enum');
    /** @psalm-trace $rule */
    return $rule;
}

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
Trace on line %d: $rule: Illuminate\Validation\Rules\Enum
