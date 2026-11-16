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

// A userland global `class_alias()` (declared by EagerClassAliasPlugin before the boot) is a loaded class, not a lazy facade
// alias: a real app auto-wires `LegacyCollection` through PHP's class table, so it resolves to the canonical class.
function appHelperResolvesGlobalClassAlias(): object
{
    $collection = app('LegacyCollection');
    /** @psalm-check-type-exact $collection = Illuminate\Support\Collection */
    return $collection;
}

function resolveHelperResolvesGlobalClassAlias(): object
{
    $collection = resolve('LegacyCollection');
    /** @psalm-check-type-exact $collection = Illuminate\Support\Collection */
    return $collection;
}

// The cold-app rule: a mis-cased spelling resolves only when the class-table entry existed when the boot ended.
// Eager `LegacyCollection` (class_alias in EagerClassAliasPlugin, before the boot) does, under any casing.
function appHelperResolvesMisCasedEagerClassAlias(): object
{
    $collection = app('legacycollection');
    /** @psalm-check-type-exact $collection = Illuminate\Support\Collection */
    return $collection;
}

// `GlobalCaseFixture` and `LazyLegacyCollection` are declared lazily by a case-sensitive autoloader (macro-fixtures.php),
// so only this exact request loads them, after the boot. A cold app autoloads the exact spelling of a class, but
// not a mis-cased one, so the mis-cased request below (the class is in the table by now) must stay `mixed`.
function appHelperResolvesExactlyCasedLazyGlobalClass(): object
{
    $exact = app('GlobalCaseFixture');
    /** @psalm-check-type-exact $exact = GlobalCaseFixture */
    return $exact;
}

function appHelperDeclinesMisCasedLazyGlobalClass(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $misCased = app('globalcasefixture');
    /** @psalm-check-type-exact $misCased = mixed */
    return $misCased;
}

// This exact request loads the alias after the boot. A cold app resolves that spelling too, but it is declined
// here (a known gap: resolvesInColdApp() only accepts AliasLoader's own exact aliases).
function appHelperDeclinesExactlyCasedLazyClassAlias(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $exact = app('LazyLegacyCollection');
    /** @psalm-check-type-exact $exact = mixed */
    return $exact;
}

function appHelperDeclinesMisCasedLazyClassAlias(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $misCased = app('lazylegacycollection');
    /** @psalm-check-type-exact $misCased = mixed */
    return $misCased;
}

// An exact AliasLoader key (`Cache`, lazy and loaded after the boot by the plugin itself) is what a cold app's
// AliasLoader resolves, so it keeps resolving, while the mis-cased `schema` above does not.
function appHelperResolvesExactFacadeAlias(): object
{
    $cache = app('Cache');
    /** @psalm-check-type-exact $cache = Illuminate\Support\Facades\Cache */
    return $cache;
}

// An alias added to AliasLoader after the boot (macro-fixtures.php; a facade file the plugin autoloads can do the
// same) is no boot-time fact, so even its exact spelling stays declined: a cold app never had it.
function appHelperDeclinesAliasRegisteredAfterBoot(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $late = app('LateCollection');
    /** @psalm-check-type-exact $late = mixed */
    return $late;
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
