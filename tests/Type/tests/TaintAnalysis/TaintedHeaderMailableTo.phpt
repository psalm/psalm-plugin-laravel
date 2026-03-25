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
%ATaintedHeader on line %d: Detected tainted header
