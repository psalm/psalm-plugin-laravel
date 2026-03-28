--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function sendCustomHtml(\Illuminate\Http\Request $request) {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->html($request->input('body'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
