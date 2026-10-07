--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicBodySinkKnownLimitation;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

function sinkAtOffsetZero(string ...$rest): void
{
    foreach ($rest as $chunk) {
        system($chunk);
    }
}

function sinkAfterOne(string $first = '', string ...$rest): void
{
    foreach ($rest as $chunk) {
        passthru($chunk);
    }
}

function sinkNamedByVariadic(string ...$rest): void
{
    foreach ($rest as $chunk) {
        exec($chunk);
    }
}

/**
 * KNOWN LIMITATION, an accepted soundness gap (see "Known limitation" in the
 * NamedArgumentTaintHandler docblock and docs/security.md).
 *
 * The value of an unknown (`zzz:`) or variadic-naming (`rest:`) named argument really is stored
 * in `$rest` and reaches the `foreach` sink in the callee's body, and plain Psalm reports
 * `TaintedShell` for each call below. The handler cannot tell a body sink from the spread false
 * positive of #1395, because both are the same flow out of the call site, so it strips all of
 * them and this fixture is silent. The previous handler behaved the same way.
 */
function unknownNameAtVariadicOffsetIsMissed(): void
{
    sinkAtOffsetZero(zzz: tainted());
}

function unknownNameAfterFixedParamIsMissed(): void
{
    sinkAfterOne('x', zzz: tainted());
}

function variadicOwnNameIsMissed(): void
{
    sinkNamedByVariadic(rest: tainted());
}
?>
--EXPECTF--
