--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentStaticOverrideVariadicParentKnownLimitation;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

class ParentAction
{
    public static function s(string ...$rest): int
    {
        return \count($rest);
    }

    public static function go(): void
    {
        static::s(sink: tainted());
    }
}

final class ChildAction extends ParentAction
{
    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public static function s(string $sink = ''): int
    {
        system($sink);

        return 0;
    }
}

/**
 * KNOWN LIMITATION, an accepted soundness gap (see "Known limitation" in the
 * NamedArgumentTaintHandler docblock and docs/security.md).
 *
 * `static::s()` is resolved against the enclosing class, `ParentAction`, whose `s()` declares a
 * variadic, so `sink:` is recorded as variadic-captured and stripped at the call site. At runtime
 * `static` is `ChildAction`, whose `s($sink)` reaches `system()`: plain Psalm reports
 * `TaintedShell` there, this fixture is silent. The late-bound class is unknowable at the point
 * the handler runs.
 */
function lateStaticBindingOverrideIsMissed(): void
{
    ChildAction::go();
}
?>
--EXPECTF--
