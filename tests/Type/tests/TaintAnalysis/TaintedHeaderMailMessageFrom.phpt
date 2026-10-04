--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function notifyUser(\Illuminate\Http\Request $request): void {
    $message = new \Illuminate\Notifications\Messages\MailMessage();
    $message->from($request->input('email'));
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of Illuminate\Notifications\Messages\MailMessage::from cannot be mixed, expecting string
TaintedHeader on line %d: Detected tainted header
