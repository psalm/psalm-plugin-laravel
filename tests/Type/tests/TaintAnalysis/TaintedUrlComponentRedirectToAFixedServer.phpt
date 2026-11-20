--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function pageRedirect(\Illuminate\Http\Request $request): void {
    redirect('https://example.com/' . $request->string('page')->toString());
}
?>
--EXPECTF--
TaintedUrlComponent on line %d: Detected tainted URL component
