--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function sendFeedback(\Illuminate\Http\Request $request) {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->subject($request->input('subject'));
}
?>
--EXPECTF--
MissingReturnType on line %d: Method sendFeedback does not have a return type, expecting void
MixedArgument on line %d: Argument 1 of Illuminate\Mail\Mailable::subject cannot be mixed, expecting string
TaintedHeader on line %d: Detected tainted header
