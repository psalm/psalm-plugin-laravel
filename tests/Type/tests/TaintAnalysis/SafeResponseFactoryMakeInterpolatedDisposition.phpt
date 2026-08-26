--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --no-cache --no-file-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * #1416 widening 2. The `attachment;` token and its separator are literal; only the filename is
 * interpolated. The unique ARGS line runs this file in its own batch (see the sibling const-folded
 * headers test for why a shared batch would mask a regression here).
 */
function makeCsvExportWithInterpolatedFilename(Request $request): void
{
    $output = (string) $request->input('csv');
    $date = \date('Y-m-d');

    Response::make($output, 200, [
        'Content-Type' => 'text/csv',
        'Content-Disposition' => "attachment; filename=\"{$date}.csv\"",
    ]);
}
?>
--EXPECTF--
