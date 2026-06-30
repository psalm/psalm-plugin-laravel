--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * substr_compare() compares a slice byte-by-byte and returns an ordering
 * value, so it is timing-unsafe exactly like strcmp(). The secret is the
 * first (haystack) operand; it must trigger TaintedUserSecret.
 */
function compareWithSubstrCompare(\Illuminate\Foundation\Auth\User $user, string $candidate): bool {
    $password = $user->getAuthPassword();
    return substr_compare($password, $candidate, 0) === 0;
}
?>
--EXPECTF--
%ATaintedUserSecret%A
