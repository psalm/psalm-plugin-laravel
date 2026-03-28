--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function shareUserInput(\Illuminate\Http\Request $request, \Illuminate\View\Factory $factory): void {
    $siteName = $request->input('site_name');
    $factory->share('siteName', $siteName);
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
