--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The spaceship operator compares byte-by-byte and returns -1/0/1, leaking
 * the ordering of the secret just like strcmp(). Comparing a secret with <=>
 * (e.g. in a usort callback) is timing-unsafe and must trigger TaintedUserSecret.
 */
function compareWithSpaceship(\Illuminate\Foundation\Auth\User $user, string $given): int {
    $password = $user->getAuthPassword();
    return $password <=> $given;
}
?>
--EXPECTF--
%ATaintedUserSecret%A
