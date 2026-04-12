--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * ResponseFactory::download() and ::file() accept a file path —
 * user-controlled paths enable arbitrary file read/download.
 */

function responseDownload(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->download($request->input('file'));
}

function responseFile(\Illuminate\Http\Request $request, \Illuminate\Routing\ResponseFactory $response): void {
    $response->file($request->input('file'));
}
?>
--EXPECTF--
%ATaintedFile on line %d: Detected tainted file handling
%ATaintedFile on line %d: Detected tainted file handling
