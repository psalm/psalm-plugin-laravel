--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Guard for SafeEscapeHelpersSpecializePerCallsite.phpt: @psalm-taint-specialize on `e()`,
 * `decrypt()` and `encrypt()` keeps the within-callsite flow of every kind their escape does not
 * strip, also across a function boundary, and the secret `decrypt()` unescapes reaches only its own
 * echo, not the literal one. Specialization broke this flow on some escape stubs
 * under earlier Psalm 7 betas (#1007), so a regression here means the annotation must go.
 */
function escapedToSql_KeepFlow(\Illuminate\Http\Request $request): void {
    \Illuminate\Support\Facades\DB::statement(e((string) $request->input('q')));
}

function decryptedToSql_KeepFlow(\Illuminate\Http\Request $request): void {
    \Illuminate\Support\Facades\DB::statement((string) decrypt((string) $request->input('q')));
}

function encryptedToSql_KeepFlow(\Illuminate\Http\Request $request): void {
    \Illuminate\Support\Facades\DB::statement(encrypt((string) $request->input('q')));
}

function escapeInCallee_KeepFlow(string $value): void {
    \Illuminate\Support\Facades\DB::statement(e($value));
}

function escapeAcrossCall_KeepFlow(\Illuminate\Http\Request $request): void {
    escapeInCallee_KeepFlow((string) $request->input('q'));
}

function echoDecryptedSecret_KeepFlow(string $payload): void {
    echo e((string) decrypt($payload));
}

function echoLiteral_KeepFlow(): void {
    echo e('hello');
}

function decryptedInputToHeader_KeepFlow(\Illuminate\Http\Request $request): void {
    header((string) decrypt((string) $request->input('q')));
}
?>
--EXPECTF--
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSql on line %d: Detected tainted SQL
TaintedSystemSecret on line %d: Detected tainted system secret leaking
TaintedUserSecret on line %d: Detected tainted user secret leaking
TaintedHeader on line %d: Detected tainted header
