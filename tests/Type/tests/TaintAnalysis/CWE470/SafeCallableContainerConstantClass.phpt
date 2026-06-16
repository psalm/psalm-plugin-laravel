--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

// CWE-470 negative case. A constant class-string carries no user input, so
// resolving it through the container must NOT raise TaintedCallable. Guards the
// container sinks (see TaintedCallableContainerResolution.phpt) against false
// positives on the ubiquitous, safe app(Foo::class) / make(Foo::class) form.
// Empty --EXPECTF-- asserts zero findings, so a regression that wrongly tainted
// a constant would surface here.
final class TrustedService {}

function safeAppConstant(): mixed
{
    return app(TrustedService::class);
}

function safeMakeConstant(\Illuminate\Contracts\Foundation\Application $app): mixed
{
    return $app->make(TrustedService::class);
}
?>
--EXPECTF--
