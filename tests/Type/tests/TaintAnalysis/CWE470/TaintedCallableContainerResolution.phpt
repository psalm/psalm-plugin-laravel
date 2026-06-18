--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// CWE-470 — unsafe reflection via the container. A user-controlled class name
// resolved through Laravel's container lets an attacker instantiate arbitrary
// classes (constructor side effects, gadget chains). Every container entry
// point marks its $abstract/$name argument as a `callable` taint sink — the
// same built-in kind Psalm applies to `new $var()` / dynamic invocation.
//
// Sinks live in:
//   - stubs/common/Foundation/helpers.phpstub      (app, resolve)
//   - stubs/common/Container/Container.phpstub      (make, makeWith — concrete)
//   - stubs/common/Contracts/Container.phpstub      (make — contract; covers the
//     idiomatic interface-typed `$this->app->make(...)` receiver)
//
// The bare `new $var()`, `$callback()`, and `call_user_func()` forms are NOT
// re-tested here: Psalm core's `callable` sink already catches them once the
// plugin supplies the Request taint source.

function unsafeAppHelper(\Illuminate\Http\Request $request): mixed
{
    return app($request->input('handler'));
}

function unsafeResolveHelper(\Illuminate\Http\Request $request): mixed
{
    return resolve($request->input('service'));
}

// Contract-typed receiver — the common dependency-injection form, e.g.
// `$this->app->make(...)` in a service provider.
function unsafeContractMake(\Illuminate\Http\Request $request, \Illuminate\Contracts\Foundation\Application $app): mixed
{
    return $app->make($request->input('service'));
}

// Concrete container, makeWith().
function unsafeConcreteMakeWith(\Illuminate\Http\Request $request, \Illuminate\Container\Container $container): mixed
{
    return $container->makeWith($request->input('service'), []);
}
?>
--EXPECTF--
%ATaintedCallable on line %d: Detected tainted text%ATaintedCallable on line %d: Detected tainted text%ATaintedCallable on line %d: Detected tainted text%ATaintedCallable on line %d: Detected tainted text%A
