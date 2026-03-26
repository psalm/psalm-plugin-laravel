--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Comparing non-secret input with === is fine — no timing attack risk
 * because the input taint type does not include user_secret or system_secret.
 */
function checkRole(\Illuminate\Http\Request $request): bool {
    return $request->input('role') === 'admin';
}
?>
--EXPECTF--

