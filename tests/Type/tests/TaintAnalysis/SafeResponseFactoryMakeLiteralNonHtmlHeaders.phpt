--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Response;

/**
 * Literal headers prove these `make()` responses are never parsed as HTML: CSV, XML, and JSON
 * are non-HTML MIME responses, while `attachment` is downloaded. Symfony's one-value string lists
 * and underscore-normalized header keys are equivalent to their scalar hyphenated forms. Both
 * gates are pinned through each receiver route because all three carry the same sink by default.
 */
function makeNonHtmlResponses(Request $request, ResponseFactory $concrete, ResponseFactoryContract $contract): void
{
    $concrete->make($request->input('csv'), 200, ['Content-Type' => 'text/csv']);
    $concrete->make($request->input('csv-list'), 200, ['Content-Type' => ['text/csv']]);
    $concrete->make($request->input('underscore-type'), 200, ['Content_Type' => 'text/csv']);
    $concrete->make($request->input('concrete-download'), 200, ['Content-Disposition' => 'attachment; filename="export.csv"']);
    $contract->make((string) $request->input('download'), 200, ['Content-Disposition' => 'attachment; filename="export.csv"']);
    $contract->make((string) $request->input('xml'), 200, ['Content-Type' => 'application/xml']);
    $contract->make((string) $request->input('contract-download'), 200, ['Content-Disposition' => 'attachment']);
    $contract->make((string) $request->input('download-list'), 200, ['Content-Disposition' => ['attachment']]);
    $contract->make((string) $request->input('underscore-download'), 200, ['Content_Disposition' => 'attachment']);
    Response::make($request->input('json'), 200, ['Content-Type' => 'application/json']);
    Response::make($request->input('facade-download'), 200, ['Content-Disposition' => 'attachment']);
}
?>
--EXPECTF--
