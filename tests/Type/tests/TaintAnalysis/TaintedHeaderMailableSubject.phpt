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
%ATaintedHeader on line %d: Detected tainted header
