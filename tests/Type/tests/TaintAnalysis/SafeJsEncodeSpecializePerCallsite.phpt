--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Js::encode() and Js::from() are @psalm-taint-specialize: taint flowing in at
 * one callsite must not pool into the return value at every other callsite
 * (issue #1007). Js::from() shares the same stub annotation and is covered by
 * this same regression because the fix is identical; it cannot be asserted
 * with a `Js::from()->toHtml()` flow because Psalm does not propagate taint
 * through the intermediate `Js` object property access.
 *
 * Without specialization, the SQL taint introduced in siteA() would pollute
 * Js::encode()'s global return node and falsely poison siteB()'s call, firing
 * a TaintedSql error at the unprepared() sink even though siteB's argument is
 * a hardcoded literal.
 */
function siteA(\Illuminate\Http\Request $request): void {
    /** @var string $sql */
    $sql = $request->input('sql');
    echo \Illuminate\Support\Js::encode($sql);
}

function siteB(): void {
    $encoded = \Illuminate\Support\Js::encode('static-payload');
    $conn = new \Illuminate\Database\Connection(new \PDO('sqlite::memory:'));
    $conn->unprepared($encoded);
}
?>
--EXPECTF--
