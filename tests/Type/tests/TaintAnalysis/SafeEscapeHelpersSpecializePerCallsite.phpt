--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * `e()`, `decrypt()` and `encrypt()` are @psalm-taint-specialize: their escape strips only some
 * kinds, and every other kind flowing in at one callsite must not pool into the return value at
 * every other callsite. Blade compiles every `{{ }}` to `e()`, so before this one
 * `{{ decrypt($x) }}` flagged every echo of the app as a secret leak.
 *
 * siteA() sends SQL taint through each helper to an echo, which no SQL kind reaches. Without
 * specialization that taint reaches the shared return node and fires TaintedSql at siteB()'s
 * sinks, whose arguments are literals. The secret kind `decrypt()` unescapes is
 * pinned in TaintedEscapeHelpersSpecializedKeepFlow.phpt, since echoing it is a true positive.
 */
function siteA_EscapeHelpers(\Illuminate\Http\Request $request): void {
    $input = (string) $request->input('q');
    echo e($input);
    echo strlen((string) decrypt($input));
    echo strlen(encrypt($input));
}

function siteB_EscapeHelpers(): void {
    echo e('hello');
    \Illuminate\Support\Facades\DB::statement(e('select 1'));
    \Illuminate\Support\Facades\DB::statement((string) decrypt('payload'));
    \Illuminate\Support\Facades\DB::statement(encrypt('payload'));
}
?>
--EXPECTF--
