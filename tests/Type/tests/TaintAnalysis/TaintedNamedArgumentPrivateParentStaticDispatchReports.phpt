--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentPrivateParentStaticDispatchReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

class Base
{
    /**
     * @psalm-impure
     * @psalm-suppress UnusedParam
     */
    private static function sink(string ...$rest): void {}

    public static function run(): void
    {
        static::sink(sink: tainted());
    }
}

class Child extends Base
{
    public static function sink(string $sink = '', string ...$rest): void
    {
        system($sink);
    }
}

/**
 * A private parent method does not make `static::` exact. PHP resolves `static::sink()` against
 * the late-bound class, `Child`, and dispatches to its PUBLIC `sink()`, where `sink:` binds to the
 * fixed parameter (runtime-confirmed). Private only pins an INSTANCE call, which PHP resolves in
 * the calling scope.
 */
function privateParentDoesNotPinStatic(): void
{
    Child::run();
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
