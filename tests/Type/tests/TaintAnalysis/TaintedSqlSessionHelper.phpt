--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeSessionHelperQuery() {
    $builder = new \Illuminate\Database\Query\Builder();
    $searchTerm = session('search');

    $builder->raw($searchTerm);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
