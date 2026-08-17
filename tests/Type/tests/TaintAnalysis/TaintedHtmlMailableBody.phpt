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
MissingReturnType on line %d: Method sendCustomHtml does not have a return type, expecting void
MixedArgument on line %d: Argument 1 of Illuminate\Mail\Mailable::html cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
