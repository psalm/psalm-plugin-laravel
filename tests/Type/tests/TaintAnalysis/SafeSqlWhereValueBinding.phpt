--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Values passed to where(), orWhere(), having(), orHaving() are PDO-bound
 * and must not be flagged as TaintedSql.
 *
 * @psalm-suppress TooFewArguments, MixedAssignment
 */
function safeWhereValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $email = $request->input('email');

    // 2-arg form: value is PDO-bound, not interpolated into SQL
    $builder->where('email', $email);
}

/** @psalm-suppress TooFewArguments, MixedAssignment */
function safeWhereValueThreeArg(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $courseId = $request->input('range');

    // 3-arg form: value is PDO-bound, not interpolated into SQL
    $builder->where('course__courses.id', '=', $courseId);
}

/** @psalm-suppress TooFewArguments, MixedAssignment */
function safeOrWhereValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $email = $request->input('email');

    $builder->orWhere('email', $email);
}

/** @psalm-suppress TooFewArguments, MixedAssignment */
function safeHavingValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $count = $request->input('min_count');

    $builder->having('total', '>', $count);
}

/** @psalm-suppress TooFewArguments, MixedAssignment */
function safeWhereNotValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $status = $request->input('status');

    $builder->whereNot('status', $status);
}

/** @psalm-suppress TooFewArguments, MixedAssignment */
function safeOrWhereNotValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $status = $request->input('status');

    $builder->orWhereNot('status', $status);
}

/** @psalm-suppress TooFewArguments, MixedAssignment */
function safeOrHavingValue(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $count = $request->input('min_count');

    $builder->orHaving('total', '>', $count);
}

/** @psalm-suppress TooFewArguments, MixedAssignment */
function safeFindById(\Illuminate\Http\Request $request): void {
    $builder = new \Illuminate\Database\Query\Builder();
    $id = $request->input('id');

    // find($id) delegates to where('id', '=', $id) — PDO-bound, cannot inject
    $builder->find($id);
}
?>
--EXPECTF--
