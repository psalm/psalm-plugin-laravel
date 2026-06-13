--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * strncmp() compares the first $length bytes character-by-character — its
 * return value still reveals partial ordering of the prefix, so it is
 * timing-unsafe even when the caller only checks the === 0 result.
 */
function comparePrefixWithStrncmp(\Illuminate\Foundation\Auth\User $user, string $prefix): bool {
    $password = $user->getAuthPassword();
    return strncmp($password, $prefix, 8) === 0;
}
?>
--EXPECTF--
%ATaintedUserSecret%A
