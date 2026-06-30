--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * hash_equals() performs constant-time comparison — the handler does not
 * watch it as a sink, so secret-tainted operands must flow through without
 * triggering TaintedUserSecret / TaintedSystemSecret.
 */
function compareSecretSafely(\Illuminate\Foundation\Auth\User $user, string $expected): bool {
    $password = $user->getAuthPassword();
    return hash_equals($password, $expected);
}
?>
--EXPECTF--
