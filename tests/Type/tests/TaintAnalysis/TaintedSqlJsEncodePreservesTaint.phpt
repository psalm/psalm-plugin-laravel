--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Js::encode() escapes html but preserves other taint kinds via @psalm-flow.
 * Using encoded output in SQL must still trigger TaintedSql.
 */
function unsafeJsSql(\Illuminate\Http\Request $request): void {
    /** @var string $name */
    $name = $request->input('name');
    $encoded = \Illuminate\Support\Js::encode($name);
    $conn = new \Illuminate\Database\Connection(new \PDO('sqlite::memory:'));
    $conn->unprepared($encoded);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
