--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Mailable cc/bcc/replyTo/from methods are header sinks —
 * user-controlled addresses or names can inject CRLF sequences
 * into email headers.
 */

function mailableFrom(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->from($request->input('email'));
}

function mailableCc(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->cc($request->input('email'));
}

function mailableBcc(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->bcc($request->input('email'));
}

function mailableReplyTo(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->replyTo($request->input('email'));
}

function mailableFromName(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->from('safe@example.com', $request->input('name'));
}

function mailableCcName(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->cc('safe@example.com', $request->input('name'));
}

function mailableBccName(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->bcc('safe@example.com', $request->input('name'));
}

function mailableReplyToName(\Illuminate\Http\Request $request): void {
    $mailable = new \Illuminate\Mail\Mailable();
    $mailable->replyTo('safe@example.com', $request->input('name'));
}
?>
--EXPECTF--
%ATaintedHeader on line %d: Detected tainted header
%ATaintedHeader on line %d: Detected tainted header
%ATaintedHeader on line %d: Detected tainted header
%ATaintedHeader on line %d: Detected tainted header
%ATaintedHeader on line %d: Detected tainted header
%ATaintedHeader on line %d: Detected tainted header
%ATaintedHeader on line %d: Detected tainted header
%ATaintedHeader on line %d: Detected tainted header
