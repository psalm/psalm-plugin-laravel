--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentBuiltinFunctionPositionMatchReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * `file_put_contents()` is a PHP-internal function: the handler cannot resolve its parameters
 * and leaves the call to Psalm. Detection of the vendor `file` sink must survive a
 * named-argument call to a PHP builtin, not just to a userland function.
 */
function builtinNamedArgumentPositionMatchReports(): void
{
    file_put_contents(filename: tainted(), data: 'x');
}
?>
--EXPECTF--
TaintedFile on line %d: Detected tainted file handling
