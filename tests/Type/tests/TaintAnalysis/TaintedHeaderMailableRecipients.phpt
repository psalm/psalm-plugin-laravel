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
MixedArgument on line %d: Argument 1 of Illuminate\Mail\Mailable::from cannot be mixed, expecting array<array-key, mixed>|object|string
TaintedHeader on line %d: Detected tainted header
MixedArgument on line %d: Argument 1 of Illuminate\Mail\Mailable::cc cannot be mixed, expecting array<array-key, mixed>|object|string
TaintedHeader on line %d: Detected tainted header
MixedArgument on line %d: Argument 1 of Illuminate\Mail\Mailable::bcc cannot be mixed, expecting array<array-key, mixed>|object|string
TaintedHeader on line %d: Detected tainted header
MixedArgument on line %d: Argument 1 of Illuminate\Mail\Mailable::replyTo cannot be mixed, expecting array<array-key, mixed>|object|string
TaintedHeader on line %d: Detected tainted header
MixedArgument on line %d: Argument 2 of Illuminate\Mail\Mailable::from cannot be mixed, expecting null|string
TaintedHeader on line %d: Detected tainted header
MixedArgument on line %d: Argument 2 of Illuminate\Mail\Mailable::cc cannot be mixed, expecting null|string
TaintedHeader on line %d: Detected tainted header
MixedArgument on line %d: Argument 2 of Illuminate\Mail\Mailable::bcc cannot be mixed, expecting null|string
TaintedHeader on line %d: Detected tainted header
MixedArgument on line %d: Argument 2 of Illuminate\Mail\Mailable::replyTo cannot be mixed, expecting null|string
TaintedHeader on line %d: Detected tainted header
