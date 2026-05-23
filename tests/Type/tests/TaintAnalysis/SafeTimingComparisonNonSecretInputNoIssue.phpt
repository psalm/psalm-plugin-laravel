--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The sink registered by TimingUnsafeComparisonHandler matches only
 * USER_SECRET | SYSTEM_SECRET. Plain user-controlled input (TaintedHtml,
 * TaintedSql, etc.) flowing into === must NOT be reported by this handler.
 */
function compareUserInputDoesNotTriggerSecretSink(\Illuminate\Http\Request $request): bool {
    /** @var string $value */
    $value = $request->input('name');
    return $value === 'admin';
}
?>
--EXPECTF--
