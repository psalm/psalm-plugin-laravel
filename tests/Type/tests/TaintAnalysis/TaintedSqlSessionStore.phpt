--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function unsafeSessionQuery(\Illuminate\Session\Store $session) {
    $builder = new \Illuminate\Database\Query\Builder();
    $searchTerm = $session->get('search');

    $builder->raw($searchTerm);
}
?>
--EXPECTF--
%ATaintedSql on line %d: Detected tainted SQL
