--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function notifyUser(\Illuminate\Http\Request $request) {
    $message = new \Illuminate\Notifications\Messages\MailMessage();
    $message->from($request->input('email'));
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header
