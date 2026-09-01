--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1 --no-file-cache --no-reflection-cache
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\ResponseFactory;

/**
 * #1416 widening 4. A literal `Content-Type` naming a media type never sniffed as HTML proves the
 * exemption on its own, with no `Content-Disposition` at all. The unique ARGS line runs this file
 * in its own batch.
 */
function makeCsvReportWithoutDisposition(Request $request, ResponseFactory $response): void
{
    $csv = (string) $request->input('report');

    $response->make($csv, 200, ['Content-type' => 'text/csv; charset=UTF-8']);
}

function makeVendorDownload(Request $request, ResponseFactory $response): void
{
    $pdf = (string) $request->input('pdf');

    $response->make($pdf, 200, ['Content-Type' => 'application/pdf']);
}

/**
 * Not part of the widening: the sitemap and SAML-metadata shapes from the issue both declare an
 * `xml`-suffixed media type, which the denylist keeps on the sink deliberately (XML can carry an
 * XHTML-namespaced `<script>`). The issue's own proposed whitelist would have exempted this; that
 * proposal was rejected in favour of the stricter denylist below.
 */
function makeSitemapStaysOnTheSink(Request $request, ResponseFactory $response): void
{
    $xml = (string) $request->input('sitemap');

    $response->make($xml, 200, ['Content-type' => 'application/xml']);
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
