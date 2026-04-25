--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-suppress MixedAssignment, MixedArrayOffset */
function unsafeWhereArrayKey(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $column = $request->input('column');

    $builder->where([$column => 'safe-value']);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
