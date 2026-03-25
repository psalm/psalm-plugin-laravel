--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function notifyWithAction(\Illuminate\Http\Request $request) {
    $message = new \Illuminate\Notifications\Messages\MailMessage();
    $message->action($request->input('label'), $request->input('url'));
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML
