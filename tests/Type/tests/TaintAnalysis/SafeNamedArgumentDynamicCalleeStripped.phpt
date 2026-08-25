--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentDynamicCalleeStripped;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * `$fn(...)` is a dynamic `FuncCall` — its `name` is an `Expr` (a `Variable`), not a
 * `Name` node, so NamedArgumentTaintHandler cannot statically name the callee at all
 * (`resolveCalleeIdCandidates()` returns no candidates for a non-`Name` callee). Every
 * named argument on it is therefore stripped, the same "genuinely unresolvable" accepted
 * false negative as a `MethodCall` on an unresolved receiver — distinct from the builtin
 * case above, where the callee IS statically named and resolves via the CallMap fallback.
 *
 * NOT a discriminating test: vanilla Psalm (no plugin) also produces no output for a
 * dynamic-variable function call like this one, so the empty `--EXPECTF--` below cannot by
 * itself prove the handler's strip did anything. It is a documentation guard pinning the
 * "genuinely unresolvable callee" behaviour, not a regression pin.
 */
function dynamicCalleeIsStripped(): void
{
    $fn = 'file_put_contents';
    $fn(filename: tainted(), data: 'x');
}
?>
--EXPECTF--
