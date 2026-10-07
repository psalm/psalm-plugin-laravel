--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentNonExactInstanceDispatchReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

class Base
{
    /** @psalm-impure */
    public function go(string ...$rest): void {}

    /** @psalm-impure */
    public static function s(string ...$rest): void {}

    public static function viaStatic(): void
    {
        static::s(sink: tainted());
    }
}

class Child extends Base
{
    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public function go(string $sink = '', string ...$rest): void
    {
        system($sink);
    }

    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public static function s(string $sink = '', string ...$rest): void
    {
        passthru($sink);
    }
}

/**
 * A receiver typed as ONE concrete class is only an upper bound for an instance call: a subclass
 * may add fixed parameters before the trailing variadic, and the call then reaches its override.
 * The handler resolves the parent's variadic signature, so it must strip only when dispatch is
 * exact (a final class, or a final or private method). `static::` is the same late-bound shape.
 */
function nonFinalReceiverReports(Base $receiver): void
{
    $receiver->go(sink: tainted());
}

function lateBoundCallsReport(): void
{
    Child::viaStatic();
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
