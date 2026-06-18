--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Comparing the password hash (a user_secret taint source) with ===
 * is timing-unsafe and must trigger TaintedUserSecret. Use hash_equals()
 * for constant-time comparison.
 */
function compareSecretWithIdentical(\Illuminate\Foundation\Auth\User $user, string $expected): bool {
    $password = $user->getAuthPassword();
    return $password === $expected;
}
?>
--EXPECTF--
%ATaintedUserSecret%A
