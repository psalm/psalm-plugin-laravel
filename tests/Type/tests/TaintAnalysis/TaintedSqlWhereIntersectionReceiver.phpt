--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace Issue1339;

/**
 * A receiver intersection can store its Laravel Builder proof in `extra_types`: the primary atomic
 * may be a template with a non-Builder bound or the query-builder contract. Both map-form calls
 * must strip the PDO-bound value taint. This silent assertion fails on any TaintedSql output. #1339
 */

/**
 * @template T of \Illuminate\Contracts\Database\Query\Builder
 * @param T&\Illuminate\Database\Query\Builder $query
 */
function safeTemplateIntersectionWhere(\Illuminate\Http\Request $request, $query): void {
    $query->where(['name' => (string) $request->input('name')]);
}

/** @param \Illuminate\Contracts\Database\Query\Builder&\Illuminate\Database\Query\Builder $query */
function safeContractIntersectionWhere(\Illuminate\Http\Request $request, $query): void {
    $query->where(['name' => (string) $request->input('name')]);
}

?>
--EXPECTF--
