--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function notifyWithAction(\Illuminate\Http\Request $request): void {
    $message = new \Illuminate\Notifications\Messages\MailMessage();
    $message->action($request->input('label'), $request->input('url'));
}
?>
--EXPECTF--
MixedArgument on line %d: Argument 1 of Illuminate\Notifications\Messages\MailMessage::action cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
MixedArgument on line %d: Argument 2 of Illuminate\Notifications\Messages\MailMessage::action cannot be mixed, expecting string
TaintedHtml on line %d: Detected tainted HTML
