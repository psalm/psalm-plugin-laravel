--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-custom-input-taint.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// One sink per flow: Psalm reports each sink node once. No (string) cast on the
// validated reads: the cast drops the rule's escape.

/** @psalm-taint-sink app_query $query */
function guardSink(string $query): void { if ($query === '') { return; } }

/** @psalm-taint-sink app_query $query */
function requestSink(mixed $query): void { if ($query === '') { return; } }

/** @psalm-taint-sink app_query $query */
function uuidSink(mixed $query): void { if ($query === '') { return; } }

/** @psalm-taint-sink app_query $query */
function emailSink(mixed $query): void { if ($query === '') { return; } }

/** Plugin-attached input sources give the custom taints psalm.xml aliases into `input`. */
function guardToken(\Illuminate\Auth\TokenGuard $guard): void {
    guardSink((string) $guard->getTokenForRequest());
}

/** Control: a stub `@psalm-taint-source input` reaches the custom sink. */
function requestInput(\Illuminate\Http\Request $request): void {
    requestSink($request->input('q'));
}

/** A rule clearing every built-in input kind clears the custom input taints too. */
function uuidRule(\Illuminate\Http\Request $request): void {
    $request->validate(['n' => 'uuid']);
    uuidSink($request->input('n'));
}

/** A partial escape keeps them. */
function emailRule(\Illuminate\Http\Request $request): void {
    $request->validate(['e' => 'email']);
    emailSink($request->input('e'));
}
?>
--EXPECTF--
TaintedCustom on line %d: Detected tainted app_query
TaintedCustom on line %d: Detected tainted app_query
TaintedCustom on line %d: Detected tainted app_query
