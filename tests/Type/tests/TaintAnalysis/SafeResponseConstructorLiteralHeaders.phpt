--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-cache --no-reflection-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;

/**
 * #1416 widening 3. `Illuminate\Http\Response::__construct` shares the same html sink and the same
 * literal-headers proof as `ResponseFactory::make()`. The unique ARGS line runs this file in its
 * own batch: this sink is a different shared node (`...Response::__construct#1`) from `make()`'s,
 * but the same first-flow-wins pruning applies to it.
 */
function makeCsvExportFromConstructor(Request $request): void
{
    $csv = (string) $request->input('csv');

    $response = new \Illuminate\Http\Response($csv, 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => 'attachment; filename="export.csv"',
    ]);

    echo $response;
}
?>
--EXPECTF--
