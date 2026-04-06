--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function notifyWithContent(\Illuminate\Http\Request $request) {
    $message = new \Illuminate\Notifications\Messages\MailMessage();
    $message->line($request->input('message'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
