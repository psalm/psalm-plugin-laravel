--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Eloquent model-fetch methods (findOrFail, find, first, etc.) must NOT propagate
 * ssrf/header taint from the lookup key ($id) to the returned model.
 *
 * The lookup key controls WHICH row is retrieved from the DB, not the content of
 * the row. DB-fetched model attributes come from the database, not from the key.
 *
 * Common false-positive pattern (observed in pixelfed):
 *   $id = $request->input('id');       // tainted
 *   $status = Status::findOrFail($id); // DB fetch — result is trusted
 *   redirect($status->url());          // must NOT fire TaintedSSRF + TaintedHeader
 *
 * Current behavior: Psalm does not propagate taint through stub functions without
 * @psalm-flow, so the fetch methods already do not propagate ssrf/header taint.
 * This test is a regression guard to catch if that behavior changes.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/686
 */

class StatusModel extends \Illuminate\Database\Eloquent\Model
{
    public function getRedirectUrl(): string
    {
        return (string) $this->getAttribute('url');
    }
}

// find/findOrFail pass mixed $id into a typed template parameter → MixedArgument needed
/** @psalm-suppress MixedAssignment, MixedArgument */
function noSsrfViaFindOrFail(\Illuminate\Http\Request $request): void
{
    $id = $request->input('id');
    $status = StatusModel::query()->findOrFail($id);
    redirect($status->getRedirectUrl());
}

/** @psalm-suppress MixedAssignment, MixedArgument */
function noSsrfViaFind(\Illuminate\Http\Request $request): void
{
    $id = $request->input('id');
    $status = StatusModel::query()->find($id);
    if ($status !== null) {
        redirect($status->getRedirectUrl());
    }
}

// where() accepts mixed as its value argument → only MixedAssignment needed
/** @psalm-suppress MixedAssignment */
function noSsrfViaFirst(\Illuminate\Http\Request $request): void
{
    $id = $request->input('id');
    $status = StatusModel::query()->where('id', $id)->first();
    if ($status !== null) {
        redirect($status->getRedirectUrl());
    }
}

/** @psalm-suppress MixedAssignment */
function noSsrfViaFirstOrFail(\Illuminate\Http\Request $request): void
{
    $id = $request->input('id');
    $status = StatusModel::query()->where('id', $id)->firstOrFail();
    redirect($status->getRedirectUrl());
}
?>
--EXPECTF--
