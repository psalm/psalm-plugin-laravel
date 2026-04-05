--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * strcmp() with non-secret input should not trigger a timing issue —
 * only user_secret and system_secret taint types are flagged.
 */
function compareRole(\Illuminate\Http\Request $request): bool {
    /** @var string $role */
    $role = $request->input('role');
    return strcmp($role, 'admin') === 0;
}
?>
--EXPECTF--

