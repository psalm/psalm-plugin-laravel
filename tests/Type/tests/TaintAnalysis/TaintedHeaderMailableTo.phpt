--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function sendWelcome(\Illuminate\Http\Request $request) {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->to($request->input('email'));
}
?>
--EXPECTF--
MissingReturnType on line %d: Method sendWelcome does not have a return type, expecting void
MixedArgument on line %d: Argument 1 of Illuminate\Mail\Mailable::to cannot be mixed, expecting array<array-key, mixed>|object|string
TaintedHeader on line %d: Detected tainted header
